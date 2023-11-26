<?php
namespace Mondu\Mondu\Observer;

use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\MonduTransactionItem;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected $name = 'CreateOrder';

    /**
     * @var CheckoutSession
     */
    private $_checkoutSession;

    /**
     * @var RequestFactory
     */
    private $_requestFactory;

    /**
     * @var \Mondu\Mondu\Helpers\Log
     */
    private $_monduLogger;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var PaymentMethod
     */
    private $paymentMethodHelper;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var MonduTransactionItem
     */
    private $monduTransactionItem;

    /**
     * @param ContextHelper $contextHelper
     * @param CheckoutSession $checkoutSession
     * @param RequestFactory $requestFactory
     * @param \Mondu\Mondu\Helpers\Log $logger
     * @param QuoteFactory $quoteFactory
     * @param OrderHelper $orderHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethod $paymentMethodHelper
     * @param MonduTransactionItem $monduTransactionItem
     */
    public function __construct(
        ContextHelper $contextHelper,
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        QuoteFactory $quoteFactory,
        OrderHelper $orderHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        MonduTransactionItem $monduTransactionItem
    ) {
        parent::__construct(
            $paymentMethodHelper,
            $monduFileLogger,
            $contextHelper
        );
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
     * Execute
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function _execute(Observer $observer)
    {
        $orderUid = $this->_checkoutSession->getMonduid();
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $createMonduDatabaseRecord = true;

        $isEditOrder = $order->getRelationParentRealId() || $order->getRelationParentId();
        $isMondu = $this->paymentMethodHelper->isMondu($payment);

        if ($isEditOrder && !$isMondu) {
            //checks if order with Mondu payment method was changed to other payment method and cancels Mondu order.
            $this->orderHelper->handlePaymentMethodChange($order);
        }

        if (!$isMondu) {
            $this->monduFileLogger->info('Not a Mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        if ($isEditOrder) {
            $this->monduFileLogger
                ->info(
                    'Order has parent id, adjusting order in Mondu. ',
                    ['orderNumber' => $order->getIncrementId()]
                );
            $this->orderHelper->handleOrderAdjustment($order);
            $orderUid = $order->getMonduReferenceId();
            $createMonduDatabaseRecord = false;
        }

        try {
            $this->monduFileLogger
                ->info('Validating order status in Mondu. ', ['orderNumber' => $order->getIncrementId()]);

            $orderData = $this->_requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];
            $authorizationData = $this->confirmAuthorizedOrder($orderData, $order->getIncrementId());
            $orderData['state'] = $authorizationData['state'];

            $order->setData('mondu_reference_id', $orderUid);
            $order->addStatusHistoryComment(__('Mondu: order id %1', $orderData['uuid']));
            $order = $this->assignMagentoStatus($order, $orderData['state']);
            $order->save();
            $this->monduFileLogger->info('Saved the order in Magento ', ['orderNumber' => $order->getIncrementId()]);

            if ($createMonduDatabaseRecord) {
                $this->_monduLogger
                    ->logTransaction($order, $orderData, null, $this->paymentMethodHelper->getCode($payment));
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
     * Confirm Authorized Order
     *
     * @param array $orderData
     * @param string $orderNumber
     */
    protected function confirmAuthorizedOrder($orderData, $orderNumber)
    {
        if ($orderData['state'] === 'authorized') {
            $authorizationData = $this->_requestFactory->create(RequestFactory::CONFIRM_ORDER)
                ->process(['orderUid' => $orderData['uuid'], 'referenceId' => $orderNumber]);
            return $authorizationData['order'];
        }
        return $orderData;
    }

    /**
     * AssignMagentoStatus
     *
     * @param Order $order
     * @param string $monduOrderState
     * @return Order
     */
    protected function assignMagentoStatus($order, $monduOrderState)
    {
        if ($monduOrderState === 'pending') {
            $order->setStatus(Order::STATE_PAYMENT_REVIEW);
        }
        return $order;
    }
}
