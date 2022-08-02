<?php
namespace Mondu\Mondu\Observer;

use Error;
use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
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
     * @var PaymentMethod
     */
    private $paymentMethodHelper;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Magento\Framework\App\RequestInterface $request,
        \Mondu\Mondu\Helpers\Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_request = $request;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
    }

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
            if ($order->canCreditmemo() || $order->canInvoice()) {
                $this->monduFileLogger->info('Trying to create a credit memo', ['orderNumber' => $order->getIncrementId()]);
                $requestParams = $this->_request->getParams();
                if(@$requestParams['creditmemo']['creditmemo_mondu_id']) {
                    $grossAmountCents = $creditMemo->getGrandTotal() * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId()
                    ];

                    $memoData = $this->_requestFactory->create(RequestFactory::MEMO)
                        ->process($data);
                    $this->_monduLogger->syncOrder($monduId);
                    $this->monduFileLogger->info('Created credit memo', ['orderNumber' => $order->getIncrementId()]);
                } else {
                    $this->monduFileLogger->info('Cant create a credit memo: no Mondu invoice id provided', ['orderNumber' => $order->getIncrementId()]);
                    throw new LocalizedException(__('You cant partially refund order before Shipment'));
                }

                return;
            } else {
                $this->monduFileLogger->info('Whole order amount is being refunded, canceling the order', ['orderNumber' => $order->getIncrementId()]);
                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if(@$cancelData['errors']) {
                    throw new LocalizedException(__('Unexpected error'));
                }

                $this->_monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(__('Mondu:  The transaction with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }

        } catch (Exception $error) {
            $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]);
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
