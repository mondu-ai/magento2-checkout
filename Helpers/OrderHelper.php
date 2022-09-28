<?php
namespace Mondu\Mondu\Helpers;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class OrderHelper
{
    private $quoteFactory;
    private $_monduLogger;
    private $_requestFactory;
    private $monduFileLogger;

    public function __construct(
        QuoteFactory $quoteFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Logger\Logger $monduFileLogger
    )
    {
        $this->quoteFactory = $quoteFactory;
        $this->_monduLogger = $logger;
        $this->_requestFactory = $requestFactory;
        $this->monduFileLogger = $monduFileLogger;
    }
    public function getLinesFromOrder(Order $order)
    {
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();

        $totalTax = round($quote->getShippingAddress()->getBaseTaxAmount(), 2);
        $shippingTotal = round($quote->getShippingAddress()->getShippingAmount(), 2) * 100;
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
                'quantity' => $quoteItem->getQty(),
                'product_sku' => $quoteItem->getSku(),
                'product_id' => $quoteItem->getProductId()
            ];
        }
        return [
            [
                'tax_cents' => $totalTax * 100,
                'shipping_price_cents' => $shippingTotal,
                'line_items' => $lineItems
            ]
        ];
    }
    public function handleOrderAdjustment($order, $orderId = null) {
        $prevOrderId = $orderId ?? $order->getRelationParentId();
        $log = $this->_monduLogger->getTransactionByIncrementId($prevOrderId);
        if(!$log || !$log['reference_id']) {
            throw new LocalizedException(__('This order was not placed with mondu'));
        }
        $orderUid = $log['reference_id'];
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();
        $lines = $this->getLinesFromOrder($order);
        $netPrice = $quote->getSubtotal();
        $adjustment =  [
            'currency' => $quote->getBaseCurrencyCode(),
            'external_reference_id' => $order->getIncrementId(),
            'amount' => [
                'net_price_cents' => $netPrice * 100,
                'tax_cents' => round($quote->getShippingAddress()->getBaseTaxAmount(), 2) * 100
            ],
            'lines' => $lines
        ];
        try {
            $editData = $this->_requestFactory->create(RequestFactory::EDIT_ORDER)
                ->setOrderUid($orderUid)
                ->process($adjustment);

            if(@$editData['errors']) {
                $this->monduFileLogger->info('Order '. $order->getIncrementId(). ': couldnt be adjusted', ['payload' => $adjustment, 'response' => $editData]);
                throw new \Exception($editData['errors'][0]['name'].' '.$editData['errors'][0]['details']);
            }
            $order->setData('mondu_reference_id', $orderUid);
            $order->addStatusHistoryComment(__('Mondu: payment adjusted for %1', $orderUid));
        } catch (\Exception $e) {
            if($orderId) {
                throw new LocalizedException(__($e->getMessage()));
            }
            $orderPayment = $order->getPayment();
            $orderPayment->deny(false);
            $order->setStatus(Order::STATE_CANCELED);
            $order->save();
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
