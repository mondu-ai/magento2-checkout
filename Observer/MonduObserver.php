<?php

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;

abstract class MonduObserver implements MonduObserverInterface
{
    /**
     * @var string
     */
    protected $name = 'MonduObserver';

    /**
     * @var PaymentMethodHelper
     */
    private $paymentMethodHelper;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var ContextHelper
     */
    protected $contextHelper;

    /**
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param MonduFileLogger $monduFileLogger
     * @param ContextHelper $contextHelper
     */
    public function __construct(
        PaymentMethodHelper $paymentMethodHelper,
        MonduFileLogger $monduFileLogger,
        ContextHelper $contextHelper
    ) {
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->monduFileLogger = $monduFileLogger;
        $this->contextHelper = $contextHelper;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $this->getOrderFromObserver($observer);
        $this->contextHelper->setConfigContextForOrder($order);

        $this->monduFileLogger
            ->info('Entered ' . $this->name . ' observer', ['orderNumber' => $order->getIncrementId()]);

        if ($this->checkOrderPlacedWithMondu($order)) {
            $this->_execute($observer);
        } else {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
        }
    }

    /**
     * Execute to be implemented in the class
     *
     * @param Observer $observer
     * @return void
     */
    abstract public function _execute(Observer $observer);

    /**
     * Check if order is placed with Mondu
     *
     * @param Order $order
     * @return bool
     */
    private function checkOrderPlacedWithMondu($order): bool
    {
        $payment = $order->getPayment();
        return $this->paymentMethodHelper->isMondu($payment);
    }

    /**
     * Gets order from different observer events
     *
     * @param Observer $observer
     * @return mixed
     */
    private function getOrderFromObserver(Observer $observer)
    {
        switch ($this->name) {
            case 'UpdateOrder':
                return $observer->getEvent()->getCreditmemo()->getOrder();
            case 'ShipOrder':
                return $observer->getEvent()->getShipment()->getOrder();
            default:
                return $observer->getEvent()->getOrder();
        }
    }
}
