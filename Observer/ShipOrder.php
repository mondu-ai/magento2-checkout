<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
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
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly InvoiceOrderHelper $invoiceOrderHelper,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly OrderRepositoryInterface $orderRepository,
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
        $currentState = $order->getState();
        $currentStatus = $order->getStatus();

        $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - START', [
            'order_id' => $order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'current_state' => $currentState,
            'current_status' => $currentStatus,
            'mondu_reference_id' => $monduId,
            'shipment_id' => $shipment->getEntityId() ?? 'new'
        ]);

        /** @var MonduLogModel $monduLog */
        $monduLog = $this->monduLogHelper->getLogCollection($monduId);
        if ($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - SKIPPED (already invoiced)', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $order->getIncrementId(),
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'reason' => 'Already invoiced using invoice orders action, skipping shipment observer'
            ]);
            $this->monduFileLogger->info(
                'Already invoiced using invoice orders action, skipping',
                ['orderNumber' => $order->getIncrementId()]
            );
            return;
        }

        $this->monduLogHelper->syncOrder($monduId);
        $logData = $this->monduLogHelper->getTransactionByOrderUid($monduId);
        $monduState = $logData['mondu_state'] ?? 'unknown';
        
        $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - Checking if can ship', [
            'order_id' => $order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'mondu_order_state' => $monduState,
            'can_ship' => $this->monduLogHelper->canShipOrder($monduId)
        ]);
        
        if (!$this->monduLogHelper->canShipOrder($monduId)) {
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - CANNOT SHIP', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $order->getIncrementId(),
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'mondu_order_state' => $monduState,
                'reason' => 'Mondu order state must be confirmed or partially_shipped, current state: ' . $monduState
            ]);
            throw new LocalizedException(
                __('Can\'t ship order: Mondu order state must be confirmed or partially_shipped')
            );
        }

        $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - Processing invoice order', [
            'order_id' => $order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'current_state' => $currentState,
            'current_status' => $currentStatus,
            'mondu_order_state' => $monduState,
            'reason' => 'Order can be shipped, sending invoice data to Mondu'
        ]);

        $this->invoiceOrderHelper->handleInvoiceOrder($order, $shipment, $monduLog);
        
        // Reload order to get updated status after invoice
        $order = $this->orderRepository->get($order->getEntityId());
        
        $this->monduFileLogger->logOrderStatus('[ORDER STATUS] ShipOrder observer - AFTER invoice processing', [
            'order_id' => $order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'previous_state' => $currentState,
            'previous_status' => $currentStatus,
            'current_state' => $order->getState(),
            'current_status' => $order->getStatus(),
            'mondu_order_state' => $monduState
        ]);
    }
}
