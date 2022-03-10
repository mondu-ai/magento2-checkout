<?php
namespace Mondu\Mondu\Observer;

use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ShipOrder implements \Magento\Framework\Event\ObserverInterface
{
    const CODE = 'mondu';

    protected $_monduLogger;
    private $_requestFactory;
    private $_config;

    public function __construct(RequestFactory $requestFactory, ConfigProvider $config, Log $logger)
    {
        $this->_requestFactory = $requestFactory;
        $this->_config = $config;
        $this->_monduLogger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $payment = $order->getPayment()->getMethodInstance();

        if ($payment->getCode() != self::CODE && $payment->getMethod() != self::CODE) {
            return;
        }
        $invoiceIds = $order->getInvoiceCollection()->getAllIds();

        if($order->getShipmentsCollection()->count() > 1) {
            return;
        }

        if($invoiceIds && !$order->canInvoice()) {
            $monduId = $order->getData('mondu_reference_id');
            $invoiceCollection = $order->getInvoiceCollection();

            if(!$this->_monduLogger->canShipOrder($monduId)) {
                throw new LocalizedException(__('Order is not confirmed yet.'));
            }

            foreach($invoiceCollection as $invoiceItem) {
                // TODO too many requests(?)
                $this->createInvoiceForItem($invoiceItem, $monduId, $shipment);
            }

            $this->_monduLogger->syncOrder($monduId);
        } else {
            throw new LocalizedException(__('Please invoice all order items first'));
        }
    }

    private function getInvoiceUrl($orderUid, $invoiceId) {
        return $this->_config->getPdfUrl($orderUid, $invoiceId);
    }

    /**
     * @throws LocalizedException
     */
    private function createInvoiceForItem($invoiceItem, $monduId, $shipment) {
        $gross_amount_cents = $invoiceItem->getGrandTotal() * 100;

        $invoice_url = $this->getInvoiceUrl($monduId, $invoiceItem->getIncrementId());
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
            'order_uid' => $monduId,
            'external_reference_id' => $invoiceItem->getIncrementId(),
            'gross_amount_cents' => $gross_amount_cents,
            'invoice_url' => $invoice_url,
            'line_items' => $lineItems
        ];

        $shipOrderData = $this->_requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($invoiceBody);

        if(!$shipOrderData) {
            throw new LocalizedException(__('Can\'t ship the order at this time'));
        }

        if(@$shipOrderData['errors']) {
            throw new LocalizedException(__('Unknown error'));
        }

        $invoiceMapping = [];

        $invoiceData = $shipOrderData['invoice'];
        $invoiceMapping[$invoiceItem->getIncrementId()] = [
            'uuid' => $invoiceData['uuid'],
            'state' => $invoiceData['state'],
//            'dueDate' => $invoiceData['due_date'],
//            'grossAmountCents' => $invoiceData['gross_amount_cents']
        ];

        $this->_monduLogger->updateLogInvoice($monduId, $invoiceMapping);

        $shipment->addComment(__('Mondu: invoice created with id %1', $shipOrderData['invoice']['uuid']));

        return $shipOrderData;
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
