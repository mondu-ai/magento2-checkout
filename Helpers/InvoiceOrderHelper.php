<?php
namespace Mondu\Mondu\Helpers;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Mondu\Mondu\Helpers\Log as MonduLogger;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class InvoiceOrderHelper
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;
    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var MonduLogger
     */
    private $monduLogger;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var MonduTransactionItem
     */
    private $monduTransactionItem;

    /**
     * @param ConfigProvider $configProvider
     * @param RequestFactory $requestFactory
     * @param Log $monduLogger
     * @param OrderHelper $orderHelper
     * @param ManagerInterface $messageManager
     * @param MonduFileLogger $monduFileLogger
     * @param MonduTransactionItem $monduTransactionitem
     */
    public function __construct(
        ConfigProvider $configProvider,
        RequestFactory $requestFactory,
        MonduLogger $monduLogger,
        OrderHelper $orderHelper,
        ManagerInterface $messageManager,
        MonduFileLogger $monduFileLogger,
        MonduTransactionItem $monduTransactionitem
    ) {
        $this->configProvider = $configProvider;
        $this->requestFactory = $requestFactory;
        $this->monduLogger = $monduLogger;
        $this->orderHelper = $orderHelper;
        $this->messageManager = $messageManager;
        $this->monduFileLogger = $monduFileLogger;
        $this->monduTransactionItem = $monduTransactionitem;
    }

    /**
     * HandleInvoiceOrder
     *
     * @param Order $order
     * @param mixed $shipment
     * @param mixed $monduLog
     * @return void
     * @throws LocalizedException
     */
    public function handleInvoiceOrder(Order $order, $shipment, $monduLog = null)
    {
        $monduId = $order->getData('mondu_reference_id');
        $this->monduFileLogger
            ->info(
                'InvoiceOrderHelper: handleInvoiceOrder',
                [
                    'orderNumber' => $order->getIncrementId(),
                    'monduId' => $monduId]
            );

        if (!$monduLog) {
            $monduLog = $this->monduLogger->getLogCollection($monduId);
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
     * Creates invoice for whole order
     *
     * @param Order $order
     * @return mixed
     * @throws LocalizedException
     */
    public function createInvoiceForWholeOrder(Order $order)
    {
        $monduId = $order->getMonduReferenceId();
        $body = [
            'order_uid' => $monduId,
            'invoice_url' => 'https://not.available',
            'external_reference_id' => $order->getIncrementId(),
            'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100
        ];

        $this->monduFileLogger
            ->info(
                'InvoiceOrderHelper: createInvoiceForWholeOrder',
                [
                    'orderNumber' => $order->getIncrementId(),
                    'body' => $body
                ]
            );

        $data = $this->requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($body);

        if ($data && !isset($data['errors'])) {
            $this->monduLogger->updateLogSkipObserver($monduId, true);

            $invoiceMapping[$order->getIncrementId()] = [
                'uuid' => $data['invoice']['uuid'],
                'state' => $data['invoice']['state'],
                'local_id' => $order->getIncrementId()
            ];

            $this->monduLogger->updateLogInvoice($monduId, $invoiceMapping);

            $this->monduLogger->syncOrder($monduId);
        }

        return $data;
    }

    /**
     * Creates invoices for order
     *
     * @param Order $order
     * @param mixed $shipment
     * @param mixed $monduLog
     * @return void
     */
    public function createOrderInvoices($order, $shipment, $monduLog)
    {
        $this->monduFileLogger
            ->info(
                'InvoiceOrderHelper: createOrderInvoices',
                [
                    'orderNumber' => $order->getIncrementId()
                ]
            );

        $invoiceMapping = $this->getInvoiceMapping($monduLog);
        $monduId = $order->getData('mondu_reference_id');
        $invoiceCollection = $order->getInvoiceCollection();
        $createdInvoices = $this->getCreatedInvoices($monduLog);

        $externalReferenceIdMapping = $this->getExternalReferenceIdMapping($monduLog->getId());

        $this->processInvoiceCollection(
            $monduId,
            $invoiceCollection,
            $shipment,
            $createdInvoices,
            $invoiceMapping,
            $externalReferenceIdMapping
        );
        $this->monduLogger->syncOrder($monduId);
    }

    /**
     * ProcessInvoiceCollection
     *
     * @param string $monduId
     * @param Collection $invoiceCollection
     * @param mixed $shipment
     * @param mixed $createdInvoices
     * @param mixed $invoiceMapping
     * @param array $externalReferenceIdMapping
     * @return void
     */
    private function processInvoiceCollection(
        string $monduId,
        Collection $invoiceCollection,
        $shipment,
        $createdInvoices,
        $invoiceMapping,
        $externalReferenceIdMapping
    ) {
        foreach ($invoiceCollection as $invoiceItem) {
            if (!in_array($invoiceItem->getEntityId(), $createdInvoices)) {
                $this->createInvoiceForItem(
                    $monduId,
                    $invoiceItem,
                    $shipment,
                    $invoiceMapping,
                    $externalReferenceIdMapping
                );

                $this->monduFileLogger
                    ->info(
                        'InvoiceOrderHelper: Invoice sent to mondu ' .
                        $invoiceItem->getIncrementId()
                    );
            }
        }
    }

    /**
     * CreateInvoiceForItem
     *
     * @param string $monduId
     * @param mixed $invoiceItem
     * @param mixed $shipment
     * @param array $invoiceMapping
     * @param array $externalReferenceIdMapping
     * @return void
     * @throws LocalizedException
     */
    private function createInvoiceForItem(
        $monduId,
        $invoiceItem,
        $shipment,
        &$invoiceMapping,
        $externalReferenceIdMapping
    ) {
        $invoiceBody = $this->getInvoiceItemBody($monduId, $invoiceItem, $externalReferenceIdMapping);
        $shipOrderData = $this->requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($invoiceBody);

        $this->monduFileLogger
            ->info(
                'InvoiceOrderHelper: createInvoiceForItem',
                [
                    'monduId' => $monduId,
                    'body' => $invoiceBody
                ]
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
     * GetInvoiceItemBody
     *
     * @param string $monduId
     * @param mixed $invoiceItem
     * @param mixed $externalReferenceIdMapping
     * @return mixed
     */
    private function getInvoiceItemBody($monduId, $invoiceItem, $externalReferenceIdMapping)
    {
        $gross_amount_cents = round($invoiceItem->getBaseGrandTotal(), 2) * 100;
        $invoice_url = $this->configProvider->getPdfUrl($monduId, $invoiceItem->getIncrementId());
        $invoiceBody = [
            'order_uid' => $monduId,
            'external_reference_id' => $invoiceItem->getIncrementId(),
            'gross_amount_cents' => $gross_amount_cents,
            'invoice_url' => $invoice_url,
        ];

        return $this->orderHelper->addLineItemsToInvoice($invoiceItem, $invoiceBody, $externalReferenceIdMapping);
    }

    /**
     * GetInvoiceMapping
     *
     * @param mixed $monduLog
     * @return array|mixed
     */
    private function getInvoiceMapping($monduLog)
    {
        if ($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            return json_decode($monduLog->getAddons(), true);
        }

        return [];
    }

    /**
     * Returns array of local invoice ids that are already saved in mondu system
     *
     * @param mixed $monduLog
     * @return array
     */
    private function getCreatedInvoices($monduLog)
    {
        $createdInvoices = [];

        if ($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            $invoices = json_decode($monduLog->getAddons(), true);
            $createdInvoices = array_values(array_map(function ($item) {
                return $item['local_id'];
            }, $invoices));
        }

        return $createdInvoices;
    }

    /**
     * HandleInvoiceOrderErrors
     *
     * @param string $monduId
     * @param mixed $data
     * @return bool
     * @throws LocalizedException
     */
    private function handleInvoiceOrderErrors($monduId, $data)
    {
        if (!$data) {
            $this->monduLogger->updateLogSkipObserver($monduId, true);
            $this->messageManager->addErrorMessage(
                'Mondu: Unexpected error: Order could not be found, please contact Mondu Support to resolve this issue.'
            );
            return false;
        }

        if (isset($data['errors'])) {
            $this->monduFileLogger->info('InvoiceOrderHelper: handleInvoiceOrderErrors', ['errors' => $data['errors']]);
            throw new LocalizedException(__('Mondu: '. $data['errors'][0]['name']. ' '. $data['errors'][0]['details']));
        }

        return true;
    }

    /**
     * UpdateInvoiceMapping
     *
     * @param string $monduId
     * @param mixed $invoiceMapping
     * @param mixed $invoiceItem
     * @param mixed $invoiceData
     * @return void
     */
    private function updateInvoiceMapping($monduId, &$invoiceMapping, $invoiceItem, $invoiceData)
    {
        $invoiceMapping[$invoiceItem->getIncrementId()] = [
            'uuid' => $invoiceData['uuid'],
            'state' => $invoiceData['state'],
            'local_id' => $invoiceItem->getId()
        ];

        $this->monduLogger->updateLogInvoice($monduId, $invoiceMapping);
    }

    /**
     * ValidateQuantities
     *
     * @param Order $order
     * @param mixed $shipment
     * @param mixed $createdInvoices
     * @return void
     * @throws LocalizedException
     */
    private function validateQuantities($order, $shipment, $createdInvoices)
    {
        $shipSkuQtyArray = [];
        $invoiceSkuQtyArray = [];

        foreach ($shipment->getItems() as $item) {
            if (!isset($shipSkuQtyArray[$item->getSku()])) {
                $shipSkuQtyArray[$item->getSku()] = 0;
            }

            $shipSkuQtyArray[$item->getSku()] += $item->getQty();
        }

        foreach ($order->getInvoiceCollection()->getItems() as $invoice) {
            if (in_array($invoice->getEntityId(), $createdInvoices)) {
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
     * GetExternalReferenceIdMapping
     *
     * @param string $monduTransactionId
     * @return array
     */
    public function getExternalReferenceIdMapping($monduTransactionId)
    {
        $mapping = [];
        $items = $this->monduTransactionItem->getCollectionFromTransactionId($monduTransactionId);
        foreach ($items as $item) {
            $mapping[$item->getOrderItemId()] = $item->getProductId(). '-'. $item->getQuoteItemId();
        }
        return $mapping;
    }
}
