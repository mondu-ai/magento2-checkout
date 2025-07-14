<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Log as MonduLogModel;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class InvoiceOrderHelper
{
    /**
     * @param ConfigProvider $configProvider
     * @param ManagerInterface $messageManager
     * @param MonduFileLogger $monduFileLogger
     * @param MonduLogHelper $monduLogHelper
     * @param MonduTransactionItem $monduTransactionItem
     * @param OrderHelper $orderHelper
     * @param RequestFactory $requestFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly ManagerInterface $messageManager,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly MonduTransactionItem $monduTransactionItem,
        private readonly OrderHelper $orderHelper,
        private readonly RequestFactory $requestFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Handles invoice creation and shipping based on Mondu configuration.
     *
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @param MonduLogModel|null $monduLog
     * @throws LocalizedException
     * @return void
     */
    public function handleInvoiceOrder(
        OrderInterface $order,
        ShipmentInterface $shipment,
        ?MonduLogModel $monduLog = null
    ): void {
        $monduId = $order->getMonduReferenceId();
        $this->monduFileLogger->info(
            'InvoiceOrderHelper: handleInvoiceOrder',
            [
                'orderNumber' => $order->getIncrementId(),
                'monduId' => $monduId,
            ]
        );

        if (!$monduLog) {
            $monduLog = $this->monduLogHelper->getLogCollection($monduId);
        }

        if ($this->configProvider->isInvoiceRequiredForShipping()) {
            $invoiceIds = $order->getInvoiceCollection()->getAllIds();

            if (!$invoiceIds) {
                throw new LocalizedException(__('Mondu: Invoice is required to ship the order.'));
            }

            $createdInvoices = $this->getCreatedInvoices($monduLog);
            $this->validateQuantities($order, $shipment, $createdInvoices);
            $this->createOrderInvoices($order, $shipment, $monduLog);
        } else {
            $invoiceData = $this->createInvoiceForWholeOrder($order);
            $this->handleInvoiceOrderErrors($monduId, $invoiceData);
        }
    }

    /**
     * Creates a single invoice for the full order and sends it to Mondu.
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return array
     */
    public function createInvoiceForWholeOrder(OrderInterface $order): array
    {
        $monduId = $order->getMonduReferenceId();
        $body = [
            'order_uid' => $monduId,
            'invoice_url' => 'https://not.available',
            'external_reference_id' => $order->getIncrementId(),
            'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100,
        ];

        $this->monduFileLogger->info(
            'InvoiceOrderHelper: createInvoiceForWholeOrder',
            ['orderNumber' => $order->getIncrementId(), 'body' => $body]
        );

        $data = $this->requestFactory->create(RequestFactory::SHIP_ORDER)->process($body);

        if ($data && !isset($data['errors'])) {
            $this->monduLogHelper->updateLogSkipObserver($monduId, true);

            $invoiceMapping[$order->getIncrementId()] = [
                'uuid' => $data['invoice']['uuid'],
                'state' => $data['invoice']['state'],
                'local_id' => $order->getIncrementId(),
            ];

            $this->monduLogHelper->updateLogInvoice($monduId, $invoiceMapping);

            $this->monduLogHelper->syncOrder($monduId);
        }

        return $data;
    }

    /**
     * Creates and sends invoices for each invoice item in the order.
     *
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @param MonduLogModel $monduLog
     * @throws LocalizedException
     * @return void
     */
    public function createOrderInvoices(
        OrderInterface $order,
        ShipmentInterface $shipment,
        MonduLogModel $monduLog
    ): void {
        $this->monduFileLogger->info(
            'InvoiceOrderHelper: createOrderInvoices',
            ['orderNumber' => $order->getIncrementId()]
        );

        $invoiceMapping = $this->getInvoiceMapping($monduLog);
        $monduId = $order->getMonduReferenceId();
        $invoiceCollection = $order->getInvoiceCollection();
        $createdInvoices = $this->getCreatedInvoices($monduLog);

        $externalReferenceIdMapping = $this->getExternalReferenceIdMapping((int) $monduLog->getId());

        $this->processInvoiceCollection(
            $monduId,
            $invoiceCollection,
            $shipment,
            $createdInvoices,
            $invoiceMapping,
            $externalReferenceIdMapping
        );
        $this->monduLogHelper->syncOrder($monduId);
    }

    /**
     * Sends invoice items to Mondu if not already invoiced.
     *
     * @param string $monduId
     * @param Collection $invoiceCollection
     * @param ShipmentInterface $shipment
     * @param array $createdInvoices
     * @param array $invoiceMapping
     * @param array $externalReferenceIdMapping
     * @throws LocalizedException
     * @return void
     */
    private function processInvoiceCollection(
        string $monduId,
        Collection $invoiceCollection,
        ShipmentInterface $shipment,
        array $createdInvoices,
        array $invoiceMapping,
        array $externalReferenceIdMapping
    ): void {
        foreach ($invoiceCollection as $invoiceItem) {
            if (!in_array($invoiceItem->getEntityId(), $createdInvoices, true)) {
                $this->createInvoiceForItem(
                    $monduId,
                    $invoiceItem,
                    $shipment,
                    $invoiceMapping,
                    $externalReferenceIdMapping
                );

                $this->monduFileLogger->info(
                    'InvoiceOrderHelper: Invoice sent to mondu ' . $invoiceItem->getIncrementId()
                );
            }
        }
    }

    /**
     * Sends a single invoice item to Mondu.
     *
     * @param string $monduId
     * @param InvoiceInterface $invoiceItem
     * @param ShipmentInterface $shipment
     * @param array $invoiceMapping
     * @param array $externalReferenceIdMapping
     * @throws LocalizedException
     * @return mixed|void
     */
    private function createInvoiceForItem(
        string $monduId,
        InvoiceInterface $invoiceItem,
        ShipmentInterface $shipment,
        array &$invoiceMapping,
        array $externalReferenceIdMapping
    ) {
        $invoiceBody = $this->getInvoiceItemBody($monduId, $invoiceItem, $externalReferenceIdMapping);
        $shipOrderData = $this->requestFactory->create(RequestFactory::SHIP_ORDER)->process($invoiceBody);

        $this->monduFileLogger->info(
            'InvoiceOrderHelper: createInvoiceForItem',
            ['monduId' => $monduId, 'body' => $invoiceBody]
        );

        if (!$this->handleInvoiceOrderErrors($monduId, $shipOrderData)) {
            return;
        }

        $this->updateInvoiceMapping($monduId, $invoiceMapping, $invoiceItem, $shipOrderData['invoice']);

        if ($shipment) {
            $shipment->addComment(__('Mondu: invoice created with id %1', $shipOrderData['invoice']['uuid']));
        }

        return $shipOrderData;
    }

    /**
     * Returns Mondu request body for a single invoice item.
     *
     * @param string $monduId
     * @param InvoiceInterface $invoiceItem
     * @param array $externalReferenceIdMapping
     * @return array
     */
    private function getInvoiceItemBody(
        string $monduId,
        InvoiceInterface $invoiceItem,
        array $externalReferenceIdMapping
    ): array {
        $grossAmountCents = round((float) $invoiceItem->getBaseGrandTotal(), 2) * 100;
        $invoiceUrl = $this->configProvider->getPdfUrl($monduId, $invoiceItem->getIncrementId());
        $invoiceBody = [
            'order_uid' => $monduId,
            'external_reference_id' => $invoiceItem->getIncrementId(),
            'gross_amount_cents' => $grossAmountCents,
            'invoice_url' => $invoiceUrl,
        ];

        return $this->orderHelper->addLineItemsToInvoice($invoiceItem, $invoiceBody, $externalReferenceIdMapping);
    }

    /**
     * Returns invoice mapping from Mondu log data.
     *
     * @param MonduLogModel $monduLog
     * @return array
     */
    private function getInvoiceMapping(MonduLogModel $monduLog): array
    {
        if ($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            return $this->serializer->unserialize($monduLog->getAddons());
        }

        return [];
    }

    /**
     * Returns invoice local IDs already sent to Mondu.
     *
     * @param MonduLogModel $monduLog
     * @return array
     */
    private function getCreatedInvoices(MonduLogModel $monduLog): array
    {
        $createdInvoices = [];

        if ($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            $invoices = $this->serializer->unserialize($monduLog->getAddons());
            $createdInvoices = array_values(array_map(static fn ($item) => $item['local_id'], $invoices));
        }

        return $createdInvoices;
    }

    /**
     * Handles Mondu API errors for invoice creation.
     *
     * @param string $monduId
     * @param array $data
     * @throws LocalizedException
     * @return bool
     */
    private function handleInvoiceOrderErrors(string $monduId, array $data): bool
    {
        if (!$data) {
            $this->monduLogHelper->updateLogSkipObserver($monduId, true);
            $this->messageManager->addErrorMessage(
                'Mondu: Unexpected error: Order could not be found, please contact Mondu Support to resolve this issue.'
            );
            return false;
        }

        if (isset($data['errors'])) {
            $this->monduFileLogger->info(
                'InvoiceOrderHelper: handleInvoiceOrderErrors',
                ['errors' => $data['errors']]
            );
            throw new LocalizedException(
                __('Mondu: ' . $data['errors'][0]['name'] . ' ' . $data['errors'][0]['details'])
            );
        }

        return true;
    }

    /**
     * Updates invoice mapping and syncs with Mondu log.
     *
     * @param string $monduId
     * @param array $invoiceMapping
     * @param InvoiceInterface $invoiceItem
     * @param array $invoiceData
     * @return void
     */
    private function updateInvoiceMapping(
        string $monduId,
        array &$invoiceMapping,
        InvoiceInterface $invoiceItem,
        array $invoiceData
    ): void {
        $invoiceMapping[$invoiceItem->getIncrementId()] = [
            'uuid' => $invoiceData['uuid'],
            'state' => $invoiceData['state'],
            'local_id' => $invoiceItem->getId(),
        ];

        $this->monduLogHelper->updateLogInvoice($monduId, $invoiceMapping);
    }

    /**
     * Validates that shipment and invoice quantities match.
     *
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @param array $createdInvoices
     * @throws LocalizedException
     * @return void
     */
    private function validateQuantities(
        OrderInterface $order,
        ShipmentInterface $shipment,
        array $createdInvoices
    ): void {
        $shipSkuQtyArray = [];
        $invoiceSkuQtyArray = [];

        foreach ($shipment->getItems() as $item) {
            if (!isset($shipSkuQtyArray[$item->getSku()])) {
                $shipSkuQtyArray[$item->getSku()] = 0;
            }

            $shipSkuQtyArray[$item->getSku()] += $item->getQty();
        }

        foreach ($order->getInvoiceCollection()->getItems() as $invoice) {
            if (in_array($invoice->getEntityId(), $createdInvoices, true)) {
                continue;
            }
            foreach ($invoice->getAllItems() as $i) {
                $price = (float) $i->getBasePrice();
                if (!$price) {
                    continue;
                }

                if (!isset($invoiceSkuQtyArray[$i->getSku()])) {
                    $invoiceSkuQtyArray[$i->getSku()] = 0;
                }

                $invoiceSkuQtyArray[$i->getSku()] += $i->getQty();
            }
        }

        foreach ($shipSkuQtyArray as $key => $shipSkuQty) {
            if (!isset($invoiceSkuQtyArray[$key]) || $invoiceSkuQtyArray[$key] != $shipSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }

        foreach ($invoiceSkuQtyArray as $key => $invoiceSkuQty) {
            if (!isset($shipSkuQtyArray[$key]) || $shipSkuQtyArray[$key] != $invoiceSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }
    }

    /**
     * Maps order items to Mondu external references.
     *
     * @param int $monduTransactionId
     * @throws LocalizedException
     * @return array
     */
    public function getExternalReferenceIdMapping(int $monduTransactionId): array
    {
        $mapping = [];
        $items = $this->monduTransactionItem->getCollectionFromTransactionId($monduTransactionId);
        foreach ($items as $item) {
            $mapping[$item->getOrderItemId()] = $item->getProductId() . '-' . $item->getQuoteItemId();
        }

        return $mapping;
    }
}
