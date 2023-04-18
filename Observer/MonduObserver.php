<?php

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
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

    public function execute(Observer $observer)
    {
        $order = $this->getOrderFromObserver($observer);
        $this->contextHelper->setConfigContextForOrder($order);

        $this->monduFileLogger->info('Entered ' . $this->name . ' observer', ['orderNumber' => $order->getIncrementId()]);

        if ($this->checkOrderPlacedWithMondu($order)) {
            $this->_execute($observer);
        } else {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
        }
    }

    abstract public function _execute(Observer $observer);

    private function checkOrderPlacedWithMondu($order): bool
    {
        $payment = $order->getPayment();
        return $this->paymentMethodHelper->isMondu($payment);
    }

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
