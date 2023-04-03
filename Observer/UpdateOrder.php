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
use Magento\Framework\Message\ManagerInterface;

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

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var \Magento\Framework\App\RequestInterface 
     */
    private $_request;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Magento\Framework\App\RequestInterface $request,
        \Mondu\Mondu\Helpers\Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        BulkActions $bulkActions,
        ManagerInterface $messageManager
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_request = $request;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->bulkActions = $bulkActions;
        $this->messageManager = $messageManager;
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
                    $grossAmountCents = round($creditMemo->getBaseGrandTotal(), 2) * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId()
                    ];

                    $memoData = $this->_requestFactory->create(RequestFactory::MEMO)
                        ->process($data);

                    if(@$memoData['errors']) {
                        $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $memoData['errors'][0]['details']]);
                        $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not send the credit note to Mondu, please contact Mondu Support to resolve this issue.');
                        return;
                    }

                    $this->monduFileLogger->info('Created credit memo', ['orderNumber' => $order->getIncrementId()]);

                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger->info('Cant create a credit memo: no Mondu invoice id provided', ['orderNumber' => $order->getIncrementId()]);
                    $logData = $this->_monduLogger->getTransactionByOrderUid($monduId);
                    if(
                        $logData['mondu_state']  !== 'shipped' &&
                        $logData['mondu_state'] !== 'partially_shipped' &&
                        $logData['mondu_state'] !== 'partially_complete' &&
                        $logData['mondu_state'] !== 'complete'
                    ) {
                        throw new LocalizedException(__('Mondu: You cant partially refund order before shipment'));
                    }

                    $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not send the credit note to Mondu, please contact Mondu Support to resolve this issue.');
                }
                return;
            } else {
                $this->monduFileLogger->info('Whole order amount is being refunded, canceling the order', ['orderNumber' => $order->getIncrementId()]);
                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if(@$cancelData['errors'] && !@$cancelData['order']) {
                    $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not cancel the order, please contact Mondu Support to resolve this issue.');
                    return;
                }

                $this->_monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(__('Mondu: The order with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }

        } catch (Exception $error) {
            $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]);
            $this->messageManager->addErrorMessage('Mondu: ' . $error->getMessage());
        }
    }
}
