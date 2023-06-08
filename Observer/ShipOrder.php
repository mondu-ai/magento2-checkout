<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;

class ShipOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected $name = 'ShipOrder';

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var Log
     */
    protected $monduLogger;

    /**
     * @var InvoiceOrderHelper
     */
    private $invoiceOrderHelper;

    /**
     * @param PaymentMethod $paymentMethodHelper
     * @param MonduFileLogger $monduFileLogger
     * @param ContextHelper $contextHelper
     * @param Log $logger
     * @param InvoiceOrderHelper $invoiceOrderHelper
     */
    public function __construct(
        PaymentMethod $paymentMethodHelper,
        MonduFileLogger $monduFileLogger,
        ContextHelper $contextHelper,
        Log $logger,
        InvoiceOrderHelper $invoiceOrderHelper
    ) {
        parent::__construct(
            $paymentMethodHelper,
            $monduFileLogger,
            $contextHelper
        );

        $this->monduFileLogger = $monduFileLogger;
        $this->monduLogger = $logger;
        $this->invoiceOrderHelper = $invoiceOrderHelper;
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
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();

        $monduLog = $this->monduLogger->getLogCollection($order->getData('mondu_reference_id'));

        if ($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger
                ->info(
                    'Already invoiced using invoice orders action, skipping',
                    ['orderNumber' => $order->getIncrementId()]
                );
            return;
        }

        $monduId = $order->getData('mondu_reference_id');

        if (!$this->monduLogger->canShipOrder($monduId)) {
            throw new LocalizedException(
                __('Can\'t ship order: Mondu order state must be confirmed or partially_shipped')
            );
        }

        $this->invoiceOrderHelper->handleInvoiceOrder($order, $shipment, $monduLog);
    }
}
