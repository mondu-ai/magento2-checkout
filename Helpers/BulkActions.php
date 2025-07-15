<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class BulkActions
{
    public const BULK_SHIP_ACTION = 'bulkShipAction';
    public const BULK_SYNC_ACTION = 'bulkSyncAction';

    /**
     * @param ConfigProvider $configProvider
     * @param InvoiceOrderHelper $invoiceOrderHelper
     * @param Logger $monduFileLogger
     * @param MonduLogHelper $monduLogHelper
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param OrderHelper $orderHelper
     * @param RequestFactory $requestFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly InvoiceOrderHelper $invoiceOrderHelper,
        private readonly Logger $monduFileLogger,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderHelper $orderHelper,
        private readonly RequestFactory $requestFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Filters order collection into Mondu and non-Mondu orders.
     *
     * @param array $orderIds
     * @return array[]
     */
    private function prepareData(array $orderIds): array
    {
        $orderCollection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $orderIds]);
        $this->monduFileLogger->info(
            'Found ' . count($orderCollection) . ' Orders where entity_id in orderIds'
        );

        $monduOrders = [];
        $notMonduOrders = [];

        /** @var OrderInterface $order */
        foreach ($orderCollection as $order) {
            if (!$order->getMonduReferenceId()) {
                $this->monduFileLogger->info(
                    'Order '
                    . $order->getIncrementId()
                    . ' is not a Mondu order, skipping'
                );
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }
            $monduOrders[] = $order;
        }

        return [$monduOrders, $notMonduOrders];
    }

    /**
     * Executes the provided Mondu bulk action method on a set of orders.
     *
     * @param array $orderIds
     * @param string $method
     * @param array $additionalData
     * @return array
     */
    public function execute(array $orderIds, string $method, array $additionalData = []): array
    {
        [$monduOrders, $notMonduOrders] = $this->prepareData($orderIds);
        $failedAttempts = [];
        $successAttempts = [];

        foreach ($monduOrders as $order) {
            try {
                $successAttempts[] = $this->{$method}($order, $additionalData);
            } catch (Exception $e) {
                $failedAttempts[] = $order->getIncrementId();
            }
        }

        return [$successAttempts, $notMonduOrders, $failedAttempts];
    }

    /**
     * Synchronizes a Mondu order and its invoices with the Mondu API.
     *
     * @param OrderInterface $order
     * @param array $additionalData
     * @throws LocalizedException
     * @return string
     */
    private function bulkSyncAction(OrderInterface $order, array $additionalData = []): string
    {
        $this->monduLogHelper->syncOrder($order->getMonduReferenceId());
        $this->monduLogHelper->syncOrderInvoices($order->getMonduReferenceId());
        $this->monduFileLogger->info('Order ' . $order->getIncrementId() . ': Successfully synced order');

        return $order->getIncrementId();
    }

    /**
     * Ships a Mondu order by creating an invoice and sending it to Mondu.
     *
     * @param OrderInterface $order
     * @param array $additionalData
     * @throws LocalizedException
     * @return string|null
     */
    private function bulkShipAction(OrderInterface $order, array $additionalData): ?string
    {
        $cronOrderStatus = $this->configProvider->getCronOrderStatus();
        $incrementId = $order->getIncrementId();

        if ($cronOrderStatus && $cronOrderStatus !== $order->getStatus()) {
            $this->monduFileLogger->info(
                'Order ' . $incrementId . ' is not in ' . $cronOrderStatus . ' status yet. Skipping'
            );

            return null;
        }

        $withLineItems = $additionalData['withLineItems'] ?? false;
        $monduLogData = $this->getMonduLogData($order);

        if ($monduLogData['mondu_state'] === 'shipped') {
            $this->monduFileLogger->info(
                'Order ' . $incrementId . ' is already in state shipped. Skipping'
            );
            return null;
        }

        $this->monduFileLogger->info(
            'Order ' . $incrementId . ' Trying to create invoice, entering shipOrder'
        );

        if (!$this->configProvider->isInvoiceRequiredCron()) {
            return $this->shipOrderWithoutInvoices($order);
        }

        $monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems);
        if (!$monduInvoice) {
            throw new Exception($incrementId);
        }

        $this->monduFileLogger->info(
            'Order '
            . $incrementId
            . ' Successfully created invoice',
            ['monduInvoice' => $monduInvoice]
        );

        return $incrementId;
    }

    /**
     * Ships all invoices of a Mondu order by sending them to Mondu API.
     *
     * @param array $monduLogData
     * @param OrderInterface $order
     * @param bool $withLineItems
     * @throws LocalizedException
     * @return array[]|null
     */
    public function shipOrder(array $monduLogData, OrderInterface $order, bool $withLineItems): ?array
    {
        $this->monduFileLogger->info(
            'Entered shipOrder function. context: ',
            [
                'monduLogData' => $monduLogData,
                'order_number' => $order->getIncrementId(),
                'withLineItems' => $withLineItems,
            ]
        );

        if (!$this->monduLogHelper->canShipOrder($monduLogData['reference_id'])) {
            $this->monduFileLogger->info(
                'Order '
                . $order->getIncrementId()
                . ': Validation Error cant be shipped because mondu state is not CONFIRMED or PARTiALLY_SHIPPED'
            );
            return null;
        }

        $invoiceCollection = $order->getInvoiceCollection();
        $invoiceCollectionData = $invoiceCollection->getData();

        if (empty($invoiceCollectionData)) {
            $this->monduFileLogger->info(
                'Order '
                . $order->getIncrementId()
                . ': Validation Error cant be shipped because it does not have an invoice'
            );
            return null;
        }

        $errors = [];
        $success = [];

        $skipInvoices = [];

        if ($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $skipInvoices = array_values(array_map(function ($item) {
                return $item['local_id'];
            }, $this->serializer->unserialize($monduLogData['addons'])));
        }

        if ($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $addons = $this->serializer->unserialize($monduLogData['addons']);
        } else {
            $addons = [];
        }

        foreach ($order->getInvoiceCollection() as $invoiceItem) {
            if (in_array($invoiceItem->getEntityId(), $skipInvoices, true)) {
                $this->monduFileLogger->info(
                    'Order '
                    . $order->getIncrementId()
                    . ': SKIPIING INVOICE item already sent to mondu'
                );
                continue;
            }
            $grossAmountCents = round($invoiceItem->getBaseGrandTotal(), 2) * 100;

            $invoiceBody = [
                'order_uid' => $monduLogData['reference_id'],
                'external_reference_id' => $invoiceItem->getIncrementId(),
                'gross_amount_cents' => $grossAmountCents,
                'invoice_url' => $this->configProvider->getPdfUrl(
                    $monduLogData['reference_id'],
                    $invoiceItem->getIncrementId()
                ),
            ];

            $externalReferenceIdMapping = $this->invoiceOrderHelper
                ->getExternalReferenceIdMapping((int) $monduLogData['entity_id']);

            if ($withLineItems) {
                $invoiceBody = $this->orderHelper
                    ->addLineItemsToInvoice($invoiceItem, $invoiceBody, $externalReferenceIdMapping);
            }

            $shipOrderData = $this->requestFactory
                ->create(RequestFactory::SHIP_ORDER)->process($invoiceBody);

            if (isset($shipOrderData['errors'])) {
                $errors[] = $order->getIncrementId();
                $this->monduFileLogger->info(
                    'Order '
                    . $order->getIncrementId()
                    . ': API ERROR Error creating invoice '
                    . $invoiceItem->getIncrementId() . $this->serializer->serialize($invoiceBody),
                    $shipOrderData['errors']
                );
                continue;
            }

            $invoiceData = $shipOrderData['invoice'];
            $this->monduFileLogger->info(
                'Order ' . $order->getIncrementId() . ': CREATED INVOICE: ',
                $shipOrderData
            );

            $addons[$invoiceItem->getIncrementId()] = [
                'uuid' => $invoiceData['uuid'],
                'state' => $invoiceData['state'],
                'local_id' => $invoiceItem->getId(),
            ];

            $success[] = $shipOrderData;
        }

        if (empty($success) && !empty($errors)) {
            return null;
        }

        if (!empty($success)) {
            $this->monduLogHelper->updateLogInvoice($monduLogData['reference_id'], $addons, true);
            $this->monduLogHelper->syncOrder($monduLogData['reference_id']);
        }

        return [$errors, $success];
    }

    /**
     * Ships a batch of Mondu orders by creating and sending invoices to the Mondu API.
     *
     * @param array $orderIds
     * @param bool $withLineItems
     * @deprecated No longer used by internal code and not recommended.
     * @see bulkShipAction
     * @return array[]
     */
    public function bulkShip(array $orderIds, bool $withLineItems = false): array
    {
        $this->monduFileLogger->info(
            'Entered bulkShip function. context: ',
            ['orderIds' => $orderIds, 'withLineItems' => $withLineItems]
        );

        $orderCollection = $this->orderCollectionFactory->create();

        $orderCollection->addFieldToFilter('entity_id', ['in' => $orderIds]);

        $notMonduOrders = [];
        $failedAttempts = [];
        $successattempts = [];

        $this->monduFileLogger->info(
            'Found '
            . count($orderCollection)
            . ' Orders where entity_id in orderIds'
        );

        foreach ($orderCollection as $order) {
            if (!$order->getMonduReferenceId()) {
                $this->monduFileLogger->info(
                    'Order '
                    . $order->getIncrementId()
                    . ' is not a Mondu order, skipping'
                );
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }

            try {
                $monduLogData = $this->getMonduLogData($order);

                $this->monduFileLogger->info(
                    'Order '
                    . $order->getIncrementId()
                    . ' Trying to create invoice, entering shipOrder'
                );

                if ($monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems)) {
                    $successattempts[] = $order->getIncrementId();
                    $this->monduFileLogger->info(
                        'Order ' . $order->getIncrementId() . ' Successfully created invoice',
                        ['monduInvoice' => $monduInvoice]
                    );
                    continue;
                }
                throw new Exception($order->getIncrementId());
            } catch (Exception $e) {
                $failedAttempts[] = $order->getIncrementId();
            }
        }

        return [$successattempts, $notMonduOrders, $failedAttempts];
    }

    /**
     * Retrieves Mondu log record for a given order.
     *
     * @param OrderInterface $order
     * @throws Exception
     * @return array
     */
    private function getMonduLogData(OrderInterface $order): array
    {
        $monduLog = $this->monduLogHelper->getLogCollection($order->getMonduReferenceId());
        $monduLogData = $monduLog->getData();

        if (empty($monduLogData)) {
            $this->monduFileLogger->info(
                'Order '
                . $order->getIncrementId()
                . ' no record found in mondu_transactions, skipping'
            );
            throw new Exception($order->getIncrementId());
        }

        return $monduLogData;
    }

    /**
     * Ships a Mondu order by creating a full invoice without line items.
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return string
     */
    private function shipOrderWithoutInvoices(OrderInterface $order): string
    {
        $data = $this->invoiceOrderHelper->createInvoiceForWholeOrder($order);
        if (isset($data['errors'])) {
            throw new Exception($order->getIncrementId());
        }

        return $order->getIncrementId();
    }
}
