<?php
namespace Mondu\Mondu\Observer;

use Error;
use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class UpdateOrder implements \Magento\Framework\Event\ObserverInterface
{
    private $_checkoutSession;
    private $_requestFactory;
    protected $_monduLogger;
    private $monduFileLogger;
    /**
     * @var BulkActions
     */
    private $bulkActions;

    /**
     * @var PaymentMethod
     */
    private $paymentMethodHelper;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Magento\Framework\App\RequestInterface $request,
        \Mondu\Mondu\Helpers\Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        BulkActions $bulkActions
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_request = $request;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->bulkActions = $bulkActions;
    }

    //TODO refactor
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();

        $order = $creditMemo->getOrder();
        $payment = $order->getPayment();
        $monduId = $order->getData('mondu_reference_id');
        $this->monduFileLogger->info('Entered UpdateOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a Mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }
        try {
            if ($order->canCreditmemo() || $order->canInvoice() || $this->_monduLogger->canCreditMemo($monduId)) {
                $this->monduFileLogger->info('Trying to create a credit memo', ['orderNumber' => $order->getIncrementId()]);
                $requestParams = $this->_request->getParams();
                if(@$requestParams['creditmemo']['creditmemo_mondu_id']) {
                    $grossAmountCents = round($creditMemo->getGrandTotal(), 2) * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId()
                    ];

                    $memoData = $this->_requestFactory->create(RequestFactory::MEMO)
                        ->process($data);

                    if(@$memoData['errors']) {
                        throw new LocalizedException(__($memoData['errors'][0]['details']));
                    }

                    $this->_monduLogger->syncOrder($monduId);
                    $this->monduFileLogger->info('Created credit memo', ['orderNumber' => $order->getIncrementId()]);

                    //TODO remove when invoice/paid webhook is implemnted
                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger->info('Cant create a credit memo: no Mondu invoice id provided', ['orderNumber' => $order->getIncrementId()]);
                    $logData = $this->_monduLogger->getTransactionByOrderUid($monduId);
                    if($logData['mondu_state']  !== 'shipped' && $logData['mondu_state'] !== 'partially_shipped' && $logData['mondu_state'] !== 'partially_complete') {
                        throw new LocalizedException(__('Mondu: You cant partially refund order before shipment'));
                    }
                    throw new LocalizedException(__('Mondu: Something went wrong'));
                }

                return;
            } else {
                $this->monduFileLogger->info('Whole order amount is being refunded, canceling the order', ['orderNumber' => $order->getIncrementId()]);
                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if(@$cancelData['errors']) {
                    throw new LocalizedException(__('Mondu: Something went wrong'));
                }

                $this->_monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(__('Mondu: The order with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }

        } catch (Exception $error) {
            $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]);
            throw new LocalizedException(__('Mondu: ' . $error->getMessage()));
        }
    }
}
