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
    private $monduFileLogger;

    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        MonduLogs $monduLogs,
        RequestFactory $requestFactory,
        ConfigProvider $configProvider,
        \Mondu\Mondu\Helpers\Logger\Logger $monduFileLogger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->monduLogs = $monduLogs;
        $this->requestFactory = $requestFactory;
        $this->configProvider = $configProvider;
        $this->monduFileLogger = $monduFileLogger;
    }

    public function bulkShip($orderIds, $withLineItems = false) {
        $this->monduFileLogger->info('Entered bulkShip function. context: ', ['orderIds' => $orderIds, 'withLineItems' => $withLineItems]);

        $orderCollection = $this->orderCollectionFactory->create();

        $orderCollection->addFieldToFilter('entity_id', ['in' => $orderIds]);

        $notMonduOrders = [];
        $failedAttempts = [];
        $successattempts = [];

        $this->monduFileLogger->info('Found '. count($orderCollection). ' Orders where entity_id in orderIds');

        foreach ($orderCollection as $order) {
            if(!$order->getMonduReferenceId()) {
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' is not a Mondu order, skipping');
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }

            try {
                $monduLog = $this->monduLogs->getLogCollection($order->getMonduReferenceId());
                $monduLogData = $monduLog->getData();

                if(empty($monduLogData)) {
                    $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' no record found in mondu_transactions, skipping');
                    throw new \Exception($order->getIncrementId());
                }

                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' Trying to create invoice, entering shipOrder');

                if($monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems)) {
                    $successattempts[] = $order->getIncrementId();
                    $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' Successfully created invoice', ['monduInvoice' => $monduInvoice]);
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
        $this->monduFileLogger->info('Entered shipOrder function. context: ', ['monduLogData' => $monduLogData, 'order_number' => $order->getIncrementId(), 'withLineItems' => $withLineItems]);
        if(!$this->monduLogs->canShipOrder($monduLogData['reference_id'])) {
            $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': Validation Error cant be shipped because mondu state is not CONFIRMED or PARTiALLY_SHIPPED');
            return false;
        }

        $invoiceCollection = $order->getInvoiceCollection();
        $invoiceCollectionData = $invoiceCollection->getData();

        if(empty($invoiceCollectionData)) {
            $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': Validation Error cant be shipped because it does not have an invoice');
            return false;
        }

        $errors = [];
        $success = [];

        $skipInvoices = [];

        if($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $skipInvoices = array_values(array_map(function ($item) {
                return $item['local_id'];
            }, json_decode($monduLogData['addons'], true)));
        }

        if($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $addons = json_decode($monduLogData['addons'], true);
        } else {
            $addons = [];
        }

        foreach ($order->getInvoiceCollection() as $invoiceItem) {
            if (in_array($invoiceItem->getEntityId(), $skipInvoices)) {
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': SKIPIING INVOICE item already sent to mondu');
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
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': API ERROR Error creating invoice '. $invoiceItem->getIncrementId(), $shipOrderData['errors']);
                continue;
            }

            $invoiceData = $shipOrderData['invoice'];
            $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': CREATED INVOICE: ', $shipOrderData);

            $addons[$invoiceItem->getIncrementId()] = [
                'uuid' => $invoiceData['uuid'],
                'state' => $invoiceData['state'],
                'local_id' => $invoiceItem->getId()
            ];

            $success[] = $shipOrderData;
        }

        if (empty($success) && !empty($errors)) {
            return false;
        }

        if (!empty($success)) {
            $this->monduLogs->updateLogInvoice($monduLogData['reference_id'], $addons, true);
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
