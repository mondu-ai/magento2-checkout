<?php

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\Log as MonduLogs;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Magento\Sales\Model\Order;

class BulkActions
{
    public const BULK_SHIP_ACTION = 'bulkShipAction';
    public const BULK_SYNC_ACTION = 'bulkSyncAction';

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var Log
     */
    private $monduLogs;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var Logger\Logger
     */
    private $monduFileLogger;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var InvoiceOrderHelper
     */
    private $invoiceOrderHelper;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param Log $monduLogs
     * @param RequestFactory $requestFactory
     * @param ConfigProvider $configProvider
     * @param Logger\Logger $monduFileLogger
     * @param OrderHelper $orderHelper
     * @param InvoiceOrderHelper $invoiceOrderHelper
     */
    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        MonduLogs $monduLogs,
        RequestFactory $requestFactory,
        ConfigProvider $configProvider,
        \Mondu\Mondu\Helpers\Logger\Logger $monduFileLogger,
        OrderHelper $orderHelper,
        InvoiceOrderHelper $invoiceOrderHelper
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->monduLogs = $monduLogs;
        $this->requestFactory = $requestFactory;
        $this->configProvider = $configProvider;
        $this->monduFileLogger = $monduFileLogger;
        $this->orderHelper = $orderHelper;
        $this->invoiceOrderHelper = $invoiceOrderHelper;
    }

    /**
     * PrepareData
     *
     * @param array $orderIds
     * @return array[]
     */
    private function prepareData($orderIds)
    {
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('entity_id', ['in' => $orderIds]);
        $this->monduFileLogger->info('Found '. count($orderCollection). ' Orders where entity_id in orderIds');

        $monduOrders = [];
        $notMonduOrders = [];

        foreach ($orderCollection as $order) {
            if (!$order->getMonduReferenceId()) {
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' is not a Mondu order, skipping');
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }
            $monduOrders[] = $order;
        }

        return [$monduOrders, $notMonduOrders];
    }

    /**
     * Execute
     *
     * @param array $orderIds
     * @param string $method
     * @param null|array $additionalData
     * @return array
     */
    public function execute($orderIds, $method, $additionalData = null)
    {
        [ $monduOrders, $notMonduOrders ] = $this->prepareData($orderIds);
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
     * BulkSyncAction
     *
     * @param Order $order
     * @param null|array $_additionalData
     * @return float|string|null
     */
    private function bulkSyncAction($order, $_additionalData)
    {
        $this->monduLogs->syncOrder($order->getMonduReferenceId());
        $this->monduLogs->syncOrderInvoices($order->getMonduReferenceId());
        $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': Successfully synced order');
        return $order->getIncrementId();
    }

    /**
     * BulkShipAction
     *
     * @param Order $order
     * @param null|array $additionalData
     * @return float|string|null
     * @throws Exception
     */
    private function bulkShipAction($order, $additionalData)
    {
        if ($this->configProvider->getCronOrderStatus() &&
            $this->configProvider->getCronOrderStatus() !== $order->getStatus()
        ) {
            $this->monduFileLogger->info(
                'Order '. $order->getIncrementId() .
                ' is not in '. $this->configProvider->getCronOrderStatus(). ' status yet. Skipping',
            );

            return null;
        }

        $withLineItems = $additionalData['withLineItems'] ?? false;
        $monduLogData = $this->getMonduLogData($order);

        if ($monduLogData['mondu_state'] === 'shipped') {
            $this->monduFileLogger->info(
                'Order '. $order->getIncrementId() .
                ' is already in state shipped. Skipping',
            );
            return null;
        }

        $this->monduFileLogger->info(
            'Order ' . $order->getIncrementId() .
            ' Trying to create invoice, entering shipOrder'
        );

        if (!$this->configProvider->isInvoiceRequiredCron()) {
            return $this->shipOrderWithoutInvoices($order);
        } elseif ($monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems)) {
            $this->monduFileLogger->info(
                'Order '. $order->getIncrementId() .
                ' Successfully created invoice',
                ['monduInvoice' => $monduInvoice]
            );
            return $order->getIncrementId();
        }

        throw new Exception($order->getIncrementId());
    }

    /**
     * ShipOrder
     *
     * @param array $monduLogData
     * @param Order $order
     * @param bool $withLineItems
     * @return array[]|false
     * @throws LocalizedException
     */
    public function shipOrder($monduLogData, $order, $withLineItems)
    {
        $this->monduFileLogger->info(
            'Entered shipOrder function. context: ',
            [
                'monduLogData' => $monduLogData,
                'order_number' => $order->getIncrementId(),
                'withLineItems' => $withLineItems
            ]
        );
        if (!$this->monduLogs->canShipOrder($monduLogData['reference_id'])) {
            $this->monduFileLogger->info(
                'Order '. $order->getIncrementId() .
                ': Validation Error cant be shipped because mondu state is not CONFIRMED or PARTiALLY_SHIPPED'
            );
            return false;
        }

        $invoiceCollection = $order->getInvoiceCollection();
        $invoiceCollectionData = $invoiceCollection->getData();

        if (empty($invoiceCollectionData)) {
            $this->monduFileLogger->info(
                'Order '. $order->getIncrementId() .
                ': Validation Error cant be shipped because it does not have an invoice'
            );
            return false;
        }

        $errors = [];
        $success = [];

        $skipInvoices = [];

        if ($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $skipInvoices = array_values(array_map(function ($item) {
                return $item['local_id'];
            }, json_decode($monduLogData['addons'], true)));
        }

        if ($monduLogData['addons'] && $monduLogData['addons'] !== 'null') {
            $addons = json_decode($monduLogData['addons'], true);
        } else {
            $addons = [];
        }

        foreach ($order->getInvoiceCollection() as $invoiceItem) {
            if (in_array($invoiceItem->getEntityId(), $skipInvoices)) {
                $this->monduFileLogger->info(
                    'Order '. $order->getIncrementId() .
                    ': SKIPIING INVOICE item already sent to mondu'
                );
                continue;
            }
            $gross_amount_cents = round($invoiceItem->getBaseGrandTotal(), 2) * 100;

            $invoiceBody = [
                'order_uid' => $monduLogData['reference_id'],
                'external_reference_id' => $invoiceItem->getIncrementId(),
                'gross_amount_cents' => $gross_amount_cents,
                'invoice_url' => $this->configProvider->getPdfUrl(
                    $monduLogData['reference_id'],
                    $invoiceItem->getIncrementId()
                ),
            ];

            $externalReferenceIdMapping = $this->invoiceOrderHelper
                ->getExternalReferenceIdMapping($monduLogData['entity_id']);

            if ($withLineItems) {
                $invoiceBody = $this->orderHelper
                    ->addLineItemsToInvoice($invoiceItem, $invoiceBody, $externalReferenceIdMapping);
            }

            $shipOrderData = $this->requestFactory
                ->create(RequestFactory::SHIP_ORDER)->process($invoiceBody);

            if (isset($shipOrderData['errors'])) {
                $errors[] = $order->getIncrementId();
                $this->monduFileLogger
                    ->info(
                        'Order '. $order->getIncrementId() .
                        ': API ERROR Error creating invoice ' .
                        $invoiceItem->getIncrementId(). json_encode($invoiceBody),
                        $shipOrderData['errors']
                    );
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

    /**
     * BulkShip
     *
     * @param array $orderIds
     * @param bool $withLineItems
     * @deprecated No longer used by internal code and not recommended.
     * @see bulkShipAction
     * @return array[]
     */
    public function bulkShip($orderIds, $withLineItems = false): array
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

        $this->monduFileLogger->info('Found '. count($orderCollection). ' Orders where entity_id in orderIds');

        foreach ($orderCollection as $order) {
            if (!$order->getMonduReferenceId()) {
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ' is not a Mondu order, skipping');
                $notMonduOrders[] = $order->getIncrementId();
                continue;
            }

            try {
                $monduLogData = $this->getMonduLogData($order);

                $this->monduFileLogger->info(
                    'Order ' . $order->getIncrementId() .
                    ' Trying to create invoice, entering shipOrder'
                );

                if ($monduInvoice = $this->shipOrder($monduLogData, $order, $withLineItems)) {
                    $successattempts[] = $order->getIncrementId();
                    $this->monduFileLogger->info(
                        'Order '. $order->getIncrementId() .
                        ' Successfully created invoice',
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
     * GetMonduLogData
     *
     * @param Order $order
     * @return array|mixed
     * @throws Exception
     */
    private function getMonduLogData($order)
    {
        $monduLog = $this->monduLogs->getLogCollection($order->getMonduReferenceId());
        $monduLogData = $monduLog->getData();

        if (empty($monduLogData)) {
            $this->monduFileLogger
                ->info(
                    'Order ' . $order->getIncrementId() .
                    ' no record found in mondu_transactions, skipping'
                );
            throw new Exception($order->getIncrementId());
        }

        return $monduLogData;
    }

    /**
     * Ships the whole order that does not have invoices
     *
     * @param Order $order
     * @return float|string|null
     * @throws Exception
     */
    private function shipOrderWithoutInvoices(Order $order)
    {
        $data = $this->invoiceOrderHelper->createInvoiceForWholeOrder($order);
        if (isset($data['errors'])) {
            throw new Exception($order->getIncrementId());
        }

        return $order->getIncrementId();
    }
}
