<?php
namespace Mondu\Mondu\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class ProcessShipmentSave
{
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
     * @param MonduFileLogger $monduFileLogger
     * @param Log $logger
     * @param InvoiceOrderHelper $invoiceOrderHelper
     */
    public function __construct(
        MonduFileLogger $monduFileLogger,
        Log $logger,
        InvoiceOrderHelper $invoiceOrderHelper
    ) {
        $this->monduFileLogger = $monduFileLogger;
        $this->monduLogger = $logger;
        $this->invoiceOrderHelper = $invoiceOrderHelper;
    }

    /**
     * After Save Processing
     *
     * @param \Magento\Sales\Model\Order\ShipmentRepository\Interceptor $subject
     * @param $shipment
     *
     * @return mixed
     * @throws LocalizedException
     */
    public function afterSave(
        \Magento\Sales\Model\Order\ShipmentRepository\Interceptor $subject,
        $shipment
    ) {
        $order = $shipment->getOrder();

        $monduId = $order->getData('mondu_reference_id');

        if(!$monduId) {
            return $shipment;
        }

        $monduLog = $this->monduLogger->getLogCollection($monduId);

        if ($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger
                ->info(
                    'Already invoiced using invoice orders action, skipping',
                    ['orderNumber' => $order->getIncrementId()]
                );

            return $shipment;
        }

        $this->monduLogger->syncOrder($monduId);

        if (!$this->monduLogger->canShipOrder($monduId)) {
            throw new LocalizedException(
                __('Can\'t ship order: Mondu order state must be confirmed or partially_shipped')
            );
        }

        $this->invoiceOrderHelper->handleInvoiceOrder($order, $shipment, $monduLog);

        return $shipment;
    }
}
