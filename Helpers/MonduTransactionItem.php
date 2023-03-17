<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Api\SearchCriteriaBuilder;
use \Magento\Framework\App\Helper\AbstractHelper;
use \Mondu\Mondu\Model\MonduTransactionItemFactory;

class MonduTransactionItem extends AbstractHelper
{
    protected $monduTransactionItem;
    private $searchCriteriaBuilder;

    public function __construct(
        MonduTransactionItemFactory $monduTransactionItem,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->monduTransactionItem = $monduTransactionItem;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }
    public function deleteRecords($transactionId) {
        $transactionItem = $this->monduTransactionItem->create();
        $transactionItem->deleteRecordsForTransaction($transactionId);
    }
    public function getCollectionFromTransactionId($monduTransactionId)
    {
        $transactionItem = $this->monduTransactionItem->create();

        $items = $transactionItem->getCollection()
            ->addFieldToFilter('mondu_transaction_id', ['eq' => $monduTransactionId])
            ->load();

        return $items;
    }

    public function createTransactionItemsForOrder($monduTransactionId, $order) {
        foreach($order->getItems() as $orderItem) {
            if (! (float) $orderItem->getBasePrice()) {
                continue;
            }

            $variationId = $orderItem->getProductId();
            $orderItem->getProduct()->load($orderItem->getProductId());
            
            if ($orderItem->getProductType() === 'configurable' && $orderItem->getHasChildren()) {
                foreach ($orderItem->getChildrenItems() as $child) {
                    $variationId = $child->getProductId();
                    $child->getProduct()->load($child->getProductId());
                    continue;
                }
            }

            $transactionItemFactory = $this->monduTransactionItem->create();
            $transactionItemFactory->addData([
                'quote_item_id' => $orderItem->getQuoteItemId(),
                'order_item_id' => $orderItem->getId(),
                'mondu_transaction_id' => $monduTransactionId,
                'product_id' => $variationId
            ]);
            $transactionItemFactory->save();
        }
    }
}