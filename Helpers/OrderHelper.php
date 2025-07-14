<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\AdditionalCosts\AdditionalCostsInterface;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class OrderHelper
{
    public const DECLINED = 'declined';
    public const CANCELED = 'canceled';
    public const SHIPPED = 'shipped';
    public const AUTHORIZED = 'authorized';

    /**
     * @param AdditionalCostsInterface $additionalCosts
     * @param CartTotalRepository $cartTotalRepository
     * @param ConfigProvider $configProvider
     * @param MonduLogHelper $monduLogHelper
     * @param QuoteFactory $quoteFactory
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        private readonly AdditionalCostsInterface $additionalCosts,
        private readonly CartTotalRepository $cartTotalRepository,
        private readonly ConfigProvider $configProvider,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly QuoteFactory $quoteFactory,
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * Returns formatted line item data for the quote.
     *
     * @param CartInterface $quote
     * @param bool $isAdjustment
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return array[]
     */
    public function getLinesFromQuote(CartInterface $quote, bool $isAdjustment = false): array
    {
        $shippingTotal = $isAdjustment
            ? round($quote->getShippingAddress()->getShippingAmount(), 2)
            : $this->cartTotalRepository->get($quote->getId())->getBaseShippingAmount();

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
                'line_items' => $lineItems,
            ],
        ];
    }

    /**
     * Sends an adjustment request for the order to Mondu.
     *
     * @param OrderInterface $order
     * @param int|null $orderId
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return void
     */
    public function handleOrderAdjustment(OrderInterface $order, ?int $orderId = null): void
    {
        $prevOrderId = $orderId ?? $order->getRelationParentId();
        $log = $this->monduLogHelper->getTransactionByIncrementId((int) $prevOrderId);

        if (!$log || !$log['reference_id']) {
            throw new LocalizedException(__('This order was not placed with Mondu'));
        }

        $orderUid = $log['reference_id'];

        $adjustment = $this->getOrderAdjustmentData($order);

        try {
            $editData = $this->requestFactory->create(RequestFactory::EDIT_ORDER)
                ->setOrderUid($orderUid)
                ->process($adjustment);

            if (isset($editData['errors'])) {
                throw new LocalizedException(
                    __($editData['errors'][0]['name'] . ' ' . $editData['errors'][0]['details'])
                );
            }
            $order->setData('mondu_reference_id', $orderUid);
            $order->addCommentToStatusHistory(__('Mondu: order with id %1 was adjusted', $orderUid));
        } catch (Exception|LocalizedException $e) {
            if ($orderId) {
                throw new LocalizedException(__('Mondu api error: %1', $e->getMessage()));
            }

            $orderPayment = $order->getPayment();
            $orderPayment->deny(false);
            $order->setStatus(Order::STATE_CANCELED);
            $order->save();
            throw new LocalizedException(__('Mondu api error: ' . $e->getMessage()));
        }
    }

    /**
     * Returns adjustment payload based on quote if available or falls back to order data.
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return array
     */
    private function getOrderAdjustmentData(OrderInterface $order): array
    {
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();

        if ($quote->getId()) {
            $adjustment = [
                'currency' => $quote->getBaseCurrencyCode(),
                'external_reference_id' => $order->getIncrementId(),
            ];

            $adjustment = $this->addLinesOrGrossAmountToOrder(
                $quote,
                $quote->getBaseGrandTotal(),
                $adjustment,
                true
            );

            return $this->addAmountToOrder($quote, $adjustment);
        }

        return [
            'currency' => $order->getBaseCurrencyCode(),
            'external_reference_id' => $order->getIncrementId(),
            'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100,
            'amount' => ['gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100],
        ];
    }

    /**
     * Returns line item data extracted from quote products.
     *
     * @param CartInterface $quote
     * @throws LocalizedException
     * @return array
     */
    private function getLineItemsFromQuote(CartInterface $quote): array
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
                'product_id' => $quoteItem->getProductId(),
            ];
        }

        return $lineItems;
    }

    /**
     * Adds line items or gross total amount to the order payload.
     *
     * @param CartInterface $quote
     * @param float $grandTotal
     * @param array $order
     * @param bool $isAdjustment
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return array
     */
    public function addLinesOrGrossAmountToOrder(
        CartInterface $quote,
        float $grandTotal,
        array $order,
        bool $isAdjustment = false
    ): array {
        $sendLines = $this->configProvider->sendLines();
        if ($sendLines) {
            $order['lines'] = $this->getLinesFromQuote($quote, $isAdjustment);
        }

        $order['gross_amount_cents'] = round($grandTotal, 2) * 100;

        return $order;
    }

    /**
     * Adds net, tax, and gross amount to the order payload.
     *
     * @param CartInterface $quote
     * @param array $order
     * @return array
     */
    public function addAmountToOrder(CartInterface $quote, array $order): array
    {
        $netPrice = $quote->getSubtotal();

        $order['amount'] = [
            'net_price_cents' => round($netPrice, 2) * 100,
            'tax_cents' => round($quote->getShippingAddress()->getBaseTaxAmount(), 2) * 100,
            'gross_amount_cents' => round($quote->getBaseGrandTotal(), 2) * 100,
        ];

        return $order;
    }

    /**
     * Adds line item data to invoice payload if line sending is enabled.
     *
     * @param InvoiceInterface $invoiceItem
     * @param array $invoice
     * @param array $externalReferenceIdMapping
     * @return array
     */
    public function addLineItemsToInvoice(
        InvoiceInterface $invoiceItem,
        array $invoice,
        array $externalReferenceIdMapping = []
    ): array {
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
                    'external_reference_id' => $externalReferenceIdMapping[$i->getOrderItemId()] ?? $variationId,
                ];
            }

            $invoice['line_items'] = $lineItems;
        }

        return $invoice;
    }

    /**
     * Cancels the original Mondu order if payment method is changed.
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return void
     */
    public function handlePaymentMethodChange(OrderInterface $order): void
    {
        $prevOrderId = $order->getRelationParentId();
        $log = $this->monduLogHelper->getTransactionByIncrementId((int) $prevOrderId);

        if (!$log || !$log['reference_id']) {
            return;
        }

        $this->requestFactory->create(RequestFactory::CANCEL)->process(['orderUid' => $log['reference_id']]);
    }

    /**
     * Returns map of parent configurable item ID to child simple item ID.
     *
     * @param array $items
     * @return array
     */
    private function getConfigurableItemIdMap(array $items): array
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
