<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger;
use Mondu\Mondu\Helpers\PaymentMethod;

class AfterPlaceOrder extends MonduObserver
{
    /**
     * @var \Mondu\Mondu\Helpers\Log
     */
    protected $monduLogger;

    /**
     * @param PaymentMethod $paymentMethodHelper
     * @param Logger $monduFileLogger
     * @param ContextHelper $contextHelper
     * @param \Mondu\Mondu\Helpers\Log $monduLogger
     */
    public function __construct(
        PaymentMethod $paymentMethodHelper,
        Logger $monduFileLogger,
        ContextHelper $contextHelper,
        \Mondu\Mondu\Helpers\Log $monduLogger
    ) {
        parent::__construct($paymentMethodHelper, $monduFileLogger, $contextHelper);
        $this->monduLogger = $monduLogger;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     * @return void
     */
    public function _execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $monduUuid = $order->getMonduReferenceId();
        $orderData = $this->monduLogger->getTransactionByOrderUid($monduUuid);

        if (isset($orderData['mondu_state']) && $orderData['mondu_state'] === 'pending') {
            $order->addStatusHistoryComment(
                __('Mondu: Order Status changed to Payment Review because it needs manual confirmation')
            );
            $order->setState(Order::STATE_PAYMENT_REVIEW);
            $order->setStatus(Order::STATE_PAYMENT_REVIEW);
            $order->save();
        } else {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->save();
        }
    }
}
