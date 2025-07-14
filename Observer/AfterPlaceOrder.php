<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;

class AfterPlaceOrder extends MonduObserver
{
    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param MonduLogHelper $monduLogHelper
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly MonduLogHelper $monduLogHelper,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @throws Exception
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();
        $orderData = $this->monduLogHelper->getTransactionByOrderUid($order->getMonduReferenceId());

        if (isset($orderData['mondu_state']) && $orderData['mondu_state'] === 'pending') {
            $order->addCommentToStatusHistory(
                __('Mondu: Order Status changed to Payment Review because it needs manual confirmation')
            );
            $order->setState(Order::STATE_PAYMENT_REVIEW);
            $order->setStatus(Order::STATE_PAYMENT_REVIEW);
            $order->save();
            return;
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->save();
    }
}
