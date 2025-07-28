<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;

abstract class MonduObserver implements MonduObserverInterface
{
    /**
     * @var string
     */
    protected string $name = 'MonduObserver';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     */
    public function __construct(
        protected ContextHelper $contextHelper,
        protected MonduFileLogger $monduFileLogger,
        protected PaymentMethodHelper $paymentMethodHelper,
    ) {
    }

    /**
     * Entry point for all Mondu observers.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $this->getOrderFromObserver($observer);
        $orderIncrementId = $order->getIncrementId();
        $this->contextHelper->setConfigContextForOrder($order);

        $this->monduFileLogger->info(
            'Entered ' . $this->name . ' observer',
            ['orderNumber' => $orderIncrementId]
        );

        if ($this->checkOrderPlacedWithMondu($order)) {
            $this->_execute($observer);
        } else {
            $this->monduFileLogger->info(
                'Not a mondu order, skipping',
                ['orderNumber' => $orderIncrementId]
            );
        }
    }

    /**
     * Execute to be implemented in the class.
     *
     * @param Observer $observer
     * @return void
     */
    abstract public function _execute(Observer $observer): void;

    /**
     * Check if order is placed with Mondu.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function checkOrderPlacedWithMondu(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        return $this->paymentMethodHelper->isMondu($payment);
    }

    /**
     * Gets order from different observer events.
     *
     * @param Observer $observer
     * @return OrderInterface
     */
    private function getOrderFromObserver(Observer $observer): OrderInterface
    {
        $event = $observer->getEvent();

        return match ($this->name) {
            'UpdateOrder' => $event->getCreditmemo()->getOrder(),
            'ShipOrder' => $event->getShipment()->getOrder(),
            default => $event->getOrder(),
        };
    }
}
