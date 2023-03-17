<?php
namespace Mondu\Mondu\Observer;

use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\MonduTransactionItem;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder implements \Magento\Framework\Event\ObserverInterface
{
    private $_checkoutSession;
    private $_requestFactory;
    private $_monduLogger;
    private $quoteFactory;
    private $monduFileLogger;
    private $paymentMethodHelper;

    /**
     * @var OrderHelper
     */
    private $orderHelper;
    
    /**
     * @var MonduTransactionItem
     */
    private $monduTransactionItem;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        QuoteFactory $quoteFactory,
        OrderHelper $orderHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        MonduTransactionItem $monduTransactionItem
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_monduLogger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderHelper = $orderHelper;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->monduTransactionItem = $monduTransactionItem;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderUid = $this->_checkoutSession->getMonduid();
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $createMonduDatabaseRecord = true;

        $isEditOrder = $order->getRelationParentRealId() || $order->getRelationParentId();
        $isMondu = $this->paymentMethodHelper->isMondu($payment);

        $this->monduFileLogger->info('Entered CreateOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if($isEditOrder && !$isMondu) {
            //checks if order with Mondu payment method was changed to other payment method and cancels Mondu order.
            $this->orderHelper->handlePaymentMethodChange($order);
        }

        if (!$isMondu) {
            $this->monduFileLogger->info('Not a Mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        if ($isEditOrder) {
            $this->monduFileLogger->info('Order has parent id, adjusting order in Mondu. ', ['orderNumber' => $order->getIncrementId()]);
            $this->handleOrderAdjustment($order);
            $orderUid = $order->getMonduReferenceId();
            $createMonduDatabaseRecord = false;
        }

        try {
            $this->monduFileLogger->info('Validating order status in Mondu. ', ['orderNumber' => $order->getIncrementId()]);
            $orderData = $this->_requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];
            $order->setData('mondu_reference_id', $orderUid);

            if($createMonduDatabaseRecord) {
                $order->addStatusHistoryComment(__('Mondu: order id %1', $orderUid));
            }

            $order->save();
            $this->monduFileLogger->info('Saved the order in Magento ', ['orderNumber' => $order->getIncrementId()]);

            if($createMonduDatabaseRecord) {
                $this->_monduLogger->logTransaction($order, $orderData, null, $this->paymentMethodHelper->getCode($payment));
            } else {
                $transactionId = $this->_monduLogger->updateLogMonduData($orderUid, null, null, null, $order->getId());

                $this->monduTransactionItem->deleteRecords($transactionId);
                $this->monduTransactionItem->createTransactionItemsForOrder($transactionId, $order);
            }

        } catch (Exception $e) {
            $this->monduFileLogger->info('Error in CreateOrder observer', ['orderNumber' => $order->getIncrementId()]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }

    /**
     * @throws LocalizedException
     */
    public function handleOrderAdjustment($order) {
        $this->orderHelper->handleOrderAdjustment($order);
    }
}
