<?php
namespace Mondu\Mondu\Helpers;

use Laminas\Filter\Boolean;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\AdditionalCosts\AdditionalCostsInterface;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class OrderHelper
{
    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var Log
     */
    private $_monduLogger;

    /**
     * @var RequestFactory
     */
    private $_requestFactory;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var CartTotalRepository
     */
    private $cartTotalRepository;

    /**
     * @var AdditionalCostsInterface
     */
    private $additionalCosts;

    /**
     * @param QuoteFactory $quoteFactory
     * @param Log $logger
     * @param RequestFactory $requestFactory
     * @param ConfigProvider $configProvider
     * @param CartTotalRepository $cartTotalRepository
     * @param AdditionalCostsInterface $additionalCosts
     */
    public function __construct(
        QuoteFactory $quoteFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        RequestFactory $requestFactory,
        ConfigProvider $configProvider,
        CartTotalRepository $cartTotalRepository,
        AdditionalCostsInterface $additionalCosts
    ) {
        $this->quoteFactory = $quoteFactory;
        $this->_monduLogger = $logger;
        $this->_requestFactory = $requestFactory;
        $this->configProvider = $configProvider;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->additionalCosts = $additionalCosts;
    }

    /**
     * GetLinesFromQuote
     *
     * @param Quote $quote
     * @param bool $isAdjustment
     * @return array[]
     * @throws NoSuchEntityException
     */
    public function getLinesFromQuote(Quote $quote, $isAdjustment = false): array
    {
        $shippingTotal = $isAdjustment ?
            round($quote->getShippingAddress()->getShippingAmount(), 2) :
            $this->cartTotalRepository->get($quote->getId())->getBaseShippingAmount();

        $totalTax = round($quote->getShippingAddress()->getBaseTaxAmount(), 2);
        $taxCompensation = $quote->getShippingAddress()->getBaseDiscountTaxCompensationAmount() ?? 0;
        $totalTax = round($totalTax + $taxCompensation, 2);
        $lineItems = $this->getLineItemsFromQuote($quote);
        $buyerFeeCents = $this->additionalCosts->getAdditionalCostsFromQuote($quote);

        return [
            [
                'buyer_fee_cents' => $buyerFeeCents,
                'shipping_price_cents' => $shippingTotal * 100,
                'tax_cents' => $totalTax * 100,
                'line_items' => $lineItems
            ]
        ];
    }

    /**
     * HandleOrderAdjustment
     *
     * @param Order $order
     * @param mixed $orderId
     * @return void
     * @throws LocalizedException
     */
    public function handleOrderAdjustment($order, $orderId = null)
    {
        $prevOrderId = $orderId ?? $order->getRelationParentId();
        $log = $this->_monduLogger->getTransactionByIncrementId($prevOrderId);

        if (!$log || !$log['reference_id']) {
            throw new LocalizedException(__('This order was not placed with Mondu'));
        }

        $orderUid = $log['reference_id'];

        $adjustment = $this->getOrderAdjustmentData($order);

        try {
            $editData = $this->_requestFactory->create(RequestFactory::EDIT_ORDER)
                ->setOrderUid($orderUid)
                ->process($adjustment);

            if (isset($editData['errors'])) {
                throw new LocalizedException(__($editData['errors'][0]['name'].' '.$editData['errors'][0]['details']));
            }
            $order->setData('mondu_reference_id', $orderUid);
            $order->addStatusHistoryComment(__('Mondu: order with id %1 was adjusted', $orderUid));
        } catch (\Exception|LocalizedException $e) {
            if ($orderId) {
                throw new LocalizedException(__('Mondu api error: %1', $e->getMessage()));
            }

            $orderPayment = $order->getPayment();
            $orderPayment->deny(false);
            $order->setStatus(Order::STATE_CANCELED);
            $order->save();
            throw new LocalizedException(__('Mondu api error: '. $e->getMessage()));
        }
    }

    /**
     * GetOrderAdjustmentData
     *
     * @param Order $order
     * @return array
     * @throws NoSuchEntityException
     */
    private function getOrderAdjustmentData($order): array
    {
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();

        if ($quote->getId()) {
            $adjustment =  [
                'currency' => $quote->getBaseCurrencyCode(),
                'external_reference_id' => $order->getIncrementId(),
            ];

            $adjustment = $this->addLinesOrGrossAmountToOrder($quote, $quote->getBaseGrandTotal(), $adjustment, true);
            return $this->addAmountToOrder($quote, $adjustment);
        }

        return [
            'currency' => $order->getBaseCurrencyCode(),
            'external_reference_id' => $order->getIncrementId(),
            'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100,
            'amount' => [
                'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100
            ]
        ];
    }

    /**
     * GetLineItemsFromQuote
     *
     * @param Quote $quote
     * @return array
     */
    private function getLineItemsFromQuote(Quote $quote): array
    {
        $lineItems = [];

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $variationId = $quoteItem->getProductId();

            $price = (float) $quoteItem->getBasePrice();
            if (!$price) {
                continue;
            }

            $quoteItem->getProduct()->load($quoteItem->getProductId());

            if ($quoteItem->getProductType() === 'configurable' && $quoteItem->getHasChildren()) {
                foreach ($quoteItem->getChildren() as $child) {
                    $variationId = $child->getProductId();
                    $child->getProduct()->load($child->getProductId());
                }
            }

            $lineItems[] = [
                'title' => $quoteItem->getName(),
                'net_price_per_item_cents' => $price * 100,
                'variation_id' => $variationId,
                'item_type' => $quoteItem->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'external_reference_id' => $variationId . '-' . $quoteItem->getItemId(),
                'quantity' => $quoteItem->getQty(),
                'product_sku' => $quoteItem->getSku(),
                'product_id' => $quoteItem->getProductId()
            ];
        }

        return $lineItems;
    }

    /**
     * AddLinesOrGrossAmountToOrder
     *
     * @param Quote $quote
     * @param float $grandTotal
     * @param array $order
     * @param Boolean $isAdjustment
     * @return array
     * @throws NoSuchEntityException
     */
    public function addLinesOrGrossAmountToOrder(Quote $quote, $grandTotal, $order, $isAdjustment = false)
    {
        $sendLines = $this->configProvider->sendLines();
        if ($sendLines) {
            $order['lines'] = $this->getLinesFromQuote($quote, $isAdjustment);
        }

        $order['gross_amount_cents'] = round($grandTotal, 2) * 100;
        return $order;
    }

    /**
     * AddAmountToOrder
     *
     * @param Quote $quote
     * @param array $order
     * @return array
     */
    public function addAmountToOrder(Quote $quote, $order)
    {
        $netPrice = $quote->getSubtotal();

        $order['amount'] = [
            'net_price_cents' => round($netPrice, 2) * 100,
            'tax_cents' => round($quote->getShippingAddress()->getBaseTaxAmount(), 2) * 100,
            'gross_amount_cents' => round($quote->getBaseGrandTotal(), 2) * 100
        ];

        return $order;
    }

    /**
     * AddLineItemsToInvoice
     *
     * @param mixed $invoiceItem
     * @param mixed $invoice
     * @param array $externalReferenceIdMapping
     * @return mixed
     */
    public function addLineItemsToInvoice($invoiceItem, $invoice, $externalReferenceIdMapping = [])
    {
        $sendLines = $this->configProvider->sendLines();

        if ($sendLines) {
            $quoteItems = $invoiceItem->getAllItems();
            $lineItems = [];

            $mapping = $this->getConfigurableItemIdMap($quoteItems);

            foreach ($quoteItems as $i) {
                $price = (float) $i->getBasePrice();
                if (!$price) {
                    continue;
                }

                $variationId = isset($mapping[$i->getProductId()]) ? $mapping[$i->getProductId()] : $i->getProductId();

                $lineItems[] = [
                    'quantity' => (int) $i->getQty(),
                    'external_reference_id' => $externalReferenceIdMapping[$i->getOrderItemId()] ?? $variationId
                ];
            }

            $invoice['line_items'] = $lineItems;
        }

        return $invoice;
    }

    /**
     * HandlePaymentMethodChange
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    public function handlePaymentMethodChange($order)
    {
        $prevOrderId = $order->getRelationParentId();
        $log = $this->_monduLogger->getTransactionByIncrementId($prevOrderId);

        if (!$log || !$log['reference_id']) {
            return;
        }

        $this->_requestFactory->create(RequestFactory::CANCEL)
            ->process(['orderUid' => $log['reference_id']]);
    }

    /**
     * GetConfigurableItemIdMap
     *
     * @param array $items
     * @return array
     */
    private function getConfigurableItemIdMap($items): array
    {
        $mapping = [];
        foreach ($items as $i) {
            $parent = $i->getOrderItem()->getParentItem();
            if ($parent) {
                $mapping[$parent->getProductId()] = $i->getProductId();
            }
        }
        return $mapping;
    }
}
