<?php

namespace Mondu\Mondu\Helpers;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\Log as MonduLogs;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Psr\Log\LoggerInterface;

class BulkActions {
    private $orderCollectionFactory;
    private $monduLogs;
    private $requestFactory;
    private $configProvider;
    private $logger;
    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        MonduLogs $monduLogs,
        RequestFactory $requestFactory,
        ConfigProvider $configProvider,
        LoggerInterface $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->monduLogs = $monduLogs;
        $this->requestFactory = $requestFactory;
        $this->configProvider = $configProvider;
        $this->logger = $logger;
    }

    public function bulkShip($orderIds, $withLineItems = false) {
        $orderCollection = $this->orderCollectionFactory->create();

        $orderCollection->addFieldToFilter('entity_id', ['in' => $orderIds]);

        $notMonduOrders = [];
        $failedAttempts = [];
        $successattempts = [];

        foreach ($orderCollection as $order) {
            if(!$order->getMonduReferenceId()) {
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }

            try {
                $monduLog = $this->monduLogs->getLogCollection($order->getMonduReferenceId());
                $monduLogData = $monduLog->getData();

                if(empty($monduLogData)) {
                    throw new \Exception($order->getIncrementId());
                }
                if($monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems)) {
                    $successattempts[] = $order->getIncrementId();
                    continue;
                }
                throw new \Exception($order->getIncrementId());
            } catch (\Exception $e) {
                $failedAttempts[] = $order->getIncrementId();
            }
        }
        return [$successattempts, $notMonduOrders, $failedAttempts];
    }

    public function shipOrder($monduLogData, $order, $withLineItems) {
        if(!$this->monduLogs->canShipOrder($monduLogData['reference_id'])) {
            $this->logger->debug('Mondu: VALIDATION ERROR: Order number '. $order->getIncrementId(). ' cant be shipped because mondu state is not CONFIRMED or PARTiALLY_SHIPPED');
            return false;
        }

        $invoiceCollection = $order->getInvoiceCollection();
        $invoiceCollectionData = $invoiceCollection->getData();

        if(empty($invoiceCollectionData)) {
            $this->logger->debug('Mondu: VALIDATION ERROR: Order number '. $order->getIncrementId(). ' cant be shipped because it does not have an invoice');
            return false;
        }

        $errors = [];
        $success = [];

        $skipInvoices = [];
        if($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $skipInvoices = array_values(array_map(function ($a) {
                return $a['local_id'];
            }, json_decode($monduLogData['addons'], true)));
        }

        foreach ($order->getInvoiceCollection() as $invoiceItem) {
            if (in_array($invoiceItem->getEntityId(), $skipInvoices)) {
                $this->logger->debug('Mondu: SKIPIING INVOICE: Order number '. $order->getIncrementId(). ' item already sent to mondu');
                continue;
            }

            $gross_amount_cents = $invoiceItem->getGrandTotal() * 100;
            $quoteItems = $invoiceItem->getAllItems();

            $lineItems = [];
            $mapping = $this->getConfigurableItemIdMap($quoteItems);

            foreach($quoteItems as $i) {
                $price = (float) $i->getBasePrice();
                if (!$price) {
                    continue;
                }
                $variationId = isset($mapping[$i->getProductId()]) ? $mapping[$i->getProductId()] : $i->getProductId();

                $lineItems[] = [
                    'quantity' => (int) $i->getQty(),
                    'external_reference_id' => $variationId
                ];
            }

            $invoiceBody = [
                'order_uid' => $monduLogData['reference_id'],
                'external_reference_id' => $invoiceItem->getIncrementId(),
                'gross_amount_cents' => $gross_amount_cents,
                'invoice_url' => $this->configProvider->getPdfUrl($monduLogData['reference_id'], $invoiceItem->getIncrementId()),
            ];

            if($withLineItems) {
                $invoiceBody['line_items'] = $lineItems;
            }

            $shipOrderData = $this->requestFactory->create(RequestFactory::SHIP_ORDER)
                ->process($invoiceBody);

            if (@$shipOrderData['errors']) {
                $errors[] = $order->getIncrementId();
                $this->logger->debug('Mondu: API ERROR: Order number '. $order->getIncrementId(). ' Error creating invoice '. $invoiceItem->getIncrementId(), $shipOrderData['errors']);
                continue;
            }

            $invoiceData = $shipOrderData['invoice'];
            $this->logger->debug('Mondu: CREATED INVOICE: Order number '. $order->getIncrementId(), $shipOrderData);
            if($monduLogData['addons'] !== 'null') {
                $addons = json_decode($monduLogData['addons'], true);
            } else {
                $addons = [];
            }

            $addons[$invoiceItem->getIncrementId()] = [
                'uuid' => $invoiceData['uuid'],
                'state' => $invoiceData['state'],
                'local_id' => $invoiceItem->getId()
            ];

            $this->monduLogs->updateLogInvoice($monduLogData['reference_id'], $addons, true);
            $success[] = $shipOrderData;
        }

        if (empty($success) && !empty($errors)) {
            return false;
        }

        if (!empty($success)) {
            $this->monduLogs->syncOrder($monduLogData['reference_id']);
        }

        return [ $errors, $success ];
    }

    private function getConfigurableItemIdMap($items) {
        $mapping = [];
        foreach($items as $i) {
            $parent = $i->getOrderItem()->getParentItem();
            if($parent) {
                $mapping[$parent->getProductId()] = $i->getProductId();
            }
        }
        return $mapping;
    }
}
