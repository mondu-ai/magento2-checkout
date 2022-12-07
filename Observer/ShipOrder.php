<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Message\ManagerInterface;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ShipOrder implements \Magento\Framework\Event\ObserverInterface
{
    protected $_monduLogger;
    private $_requestFactory;
    private $_config;
    private $monduFileLogger;
    private $paymentMethodHelper;
    private $orderHelper;
    private $messageManager;
    /**
     * @var InvoiceOrderHelper
     */
    private $invoiceOrderhelper;

    public function __construct(
        RequestFactory $requestFactory,
        ConfigProvider $config,
        Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        OrderHelper $orderHelper,
        ManagerInterface $messageManager,
        InvoiceOrderHelper $invoiceOrderhelper
    )
    {
        $this->_requestFactory = $requestFactory;
        $this->_config = $config;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->orderHelper = $orderHelper;
        $this->messageManager = $messageManager;
        $this->invoiceOrderhelper = $invoiceOrderhelper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();

        $this->monduFileLogger->info('Entered ShipOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if(!$this->validateOrderPlacedWithMondu($order)) return;

        $monduLog = $this->_monduLogger->getLogCollection($order->getData('mondu_reference_id'));

        if($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger->info('Already invoiced using invoice orders action, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }
        
        $monduId = $order->getData('mondu_reference_id');

        if($this->_config->isInvoiceRequiredForShipping()) {
            $invoiceIds = $order->getInvoiceCollection()->getAllIds();

            if(!$invoiceIds) {
                throw new LocalizedException(__('Mondu: Invoice is required to ship the order.'));
            } elseif(!$this->_monduLogger->canShipOrder($monduId)) {
                throw new LocalizedException(__('Can\'t ship order: Mondu order state must be confirmed or partially_shipped'));
            }

            $createdInvoices = $this->getCreatedInvoices($monduLog);
            $this->validateQuantities($order, $shipment, $createdInvoices);
            $this->createOrderInvoices($order, $shipment, $monduLog, $createdInvoices);
        } else {
            $invoiceData = $this->invoiceOrderhelper->createInvoiceForWholeOrder($order);
            $this->handleInvoiceOrderErrors($invoiceData, $monduId);
        }
    }

    /**
     * @throws LocalizedException
     */
    private function createInvoiceForItem($invoiceItem, $monduId, $shipment, &$invoiceMapping) {
        $gross_amount_cents = round($invoiceItem->getGrandTotal(), 2) * 100;

        $invoice_url = $this->_config->getPdfUrl($monduId, $invoiceItem->getIncrementId());

        $invoiceBody = [
            'order_uid' => $monduId,
            'external_reference_id' => $invoiceItem->getIncrementId(),
            'gross_amount_cents' => $gross_amount_cents,
            'invoice_url' => $invoice_url,
        ];

        $invoiceBody = $this->orderHelper->addLineItemsToInvoice($invoiceItem, $invoiceBody);

        $shipOrderData = $this->_requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($invoiceBody);

        if(!$this->handleInvoiceOrderErrors($shipOrderData, $monduId)) return;

        $invoiceData = $shipOrderData['invoice'];

        $invoiceMapping[$invoiceItem->getIncrementId()] = [
            'uuid' => $invoiceData['uuid'],
            'state' => $invoiceData['state'],
            'local_id' => $invoiceItem->getId()
        ];

        $this->_monduLogger->updateLogInvoice($monduId, $invoiceMapping);

        $shipment->addComment(__('Mondu: invoice created with id %1', $shipOrderData['invoice']['uuid']));

        return $shipOrderData;
    }

    private function validateQuantities($order, $shipment, $createdInvoices) {
        $shipSkuQtyArray = [];
        $invoiceSkuQtyArray = [];

        foreach($shipment->getItems() as $item) {
            if(!@$shipSkuQtyArray[$item->getSku()]) {
                $shipSkuQtyArray[$item->getSku()] = 0;
            }

            $shipSkuQtyArray[$item->getSku()] += $item->getQty();
        }

        foreach($order->getInvoiceCollection()->getItems() as $invoice) {
            if (in_array($invoice->getEntityId(), $createdInvoices)) {
                continue;
            }
            foreach($invoice->getAllItems() as $i) {
                $price = (float) $i->getBasePrice();
                if (!$price) {
                    continue;
                }

                if(!@$invoiceSkuQtyArray[$i->getSku()]) {
                    $invoiceSkuQtyArray[$i->getSku()] = 0;
                }

                $invoiceSkuQtyArray[$i->getSku()] += $i->getQty();
            }
        }

        foreach($shipSkuQtyArray as $key => $shipSkuQty) {
            if(@$invoiceSkuQtyArray[$key] !== $shipSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }

        foreach($invoiceSkuQtyArray as $key => $invoiceSkuQty) {
            if(@$shipSkuQtyArray[$key] !== $invoiceSkuQty) {
                throw new LocalizedException(__('Mondu: Invalid shipment amount'));
            }
        }
    }

    private function createOrderInvoices($order, $shipment, $monduLog, $createdInvoices) {
        if($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            $invoiceMapping = json_decode($monduLog->getAddons(), true);
        } else {
            $invoiceMapping = [];
        }

        $monduId = $order->getData('mondu_reference_id');
        $invoiceCollection = $order->getInvoiceCollection();

        if(!$this->_monduLogger->canShipOrder($monduId)) {
            throw new LocalizedException(__('Can\'t ship order: Mondu order state must be confirmed or partially_shipped'));
        }

        foreach($invoiceCollection as $invoiceItem) {
            if (in_array($invoiceItem->getEntityId(), $createdInvoices)) {
                continue;
            }
            $this->createInvoiceForItem($invoiceItem, $monduId, $shipment, $invoiceMapping);
            $this->monduFileLogger->info('ShipOrder Observer: Invoice sent to mondu '.$invoiceItem->getEntityId() . '. Order: ' . $order->getIncrementId());
        }

        $this->_monduLogger->syncOrder($monduId);
    }

    private function getCreatedInvoices($monduLog) {
        $createdInvoices = [];

        if($monduLog->getAddons() && $monduLog->getAddons() !== 'null') {
            $invoices = json_decode($monduLog->getAddons(), true);
            $createdInvoices = array_values(array_map(function ($item) {
                return $item['local_id'];
            }, $invoices));
        }

        return $createdInvoices;
    }

    private function validateOrderPlacedWithMondu($order) {
        $payment = $order->getPayment();
        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return false;
        }

        return true;
    }

    private function handleInvoiceOrderErrors($data, $monduId) {
        if (!$data) {
            $this->_monduLogger->updateLogSkipObserver($monduId, true);
            $this->messageManager->addErrorMessage('Mondu: Unexpected error: Order could not be found, please contact Mondu Support to resolve this issue.');
            return false;
        }

        if(@$data['errors']) {
            throw new LocalizedException(__($data['errors'][0]['name']. ' '. $data['errors'][0]['details']));
        }

        return true;
    }
}
