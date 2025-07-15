<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Log as MonduLogModel;

class ShipOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected string $name = 'ShipOrder';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param InvoiceOrderHelper $invoiceOrderHelper
     * @param MonduLogHelper $monduLogHelper
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly InvoiceOrderHelper $invoiceOrderHelper,
        private readonly MonduLogHelper $monduLogHelper,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Sends invoice data to Mondu when the order is shipped, if not already invoiced.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        /** @var ShipmentInterface $shipment */
        $shipment = $observer->getEvent()->getShipment();
        /** @var OrderInterface $order */
        $order = $shipment->getOrder();
        $monduId = $order->getMonduReferenceId();

        /** @var MonduLogModel $monduLog */
        $monduLog = $this->monduLogHelper->getLogCollection($monduId);
        if ($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger->info(
                'Already invoiced using invoice orders action, skipping',
                ['orderNumber' => $order->getIncrementId()]
            );
            return;
        }

        $this->monduLogHelper->syncOrder($monduId);
        if (!$this->monduLogHelper->canShipOrder($monduId)) {
            throw new LocalizedException(
                __('Can\'t ship order: Mondu order state must be confirmed or partially_shipped')
            );
        }

        $this->invoiceOrderHelper->handleInvoiceOrder($order, $shipment, $monduLog);
    }
}
