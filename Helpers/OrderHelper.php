<?php
namespace Mondu\Mondu\Helpers;

use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;

class OrderHelper
{
    private $quoteFactory;
    
    public function __construct(QuoteFactory $quoteFactory)
    {
        $this->quoteFactory = $quoteFactory;    
    }
    public function getLinesFromOrder(Order $order)
    {            
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();
        $shippingTotal = $quote->getShippingAddress()->getShippingAmount() * 100;
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
                    continue;
                }
            }

            $lineItems[] = [
                'title' => $quoteItem->getName(),
                'net_price_per_item_cents' => $price * 100,
                'variation_id' => $variationId,
                'item_type' => $quoteItem->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'external_reference_id' => $variationId,
                'tax_cents' => $quoteItem->getBaseTaxAmount() * 100,
                'quantity' => $quoteItem->getQty(),
                'product_sku' => $quoteItem->getSku(),
                'product_id' => $quoteItem->getProductId()
            ];
        }
        return [
            [
                'shipping_price_cents' => $shippingTotal,
                'line_items' => $lineItems
            ]
        ];
    }
}
