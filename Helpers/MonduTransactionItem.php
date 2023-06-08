<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\MonduTransactionItemFactory;

class MonduTransactionItem extends AbstractHelper
{
    /**
     * @var MonduTransactionItemFactory
     */
    protected $monduTransactionItem;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @param MonduTransactionItemFactory $monduTransactionItem
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        MonduTransactionItemFactory $monduTransactionItem,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->monduTransactionItem = $monduTransactionItem;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Delete Records
     *
     * @param int $transactionId
     * @return void
     */
    public function deleteRecords($transactionId)
    {
        $transactionItem = $this->monduTransactionItem->create();
        $transactionItem->deleteRecordsForTransaction($transactionId);
    }

    /**
     * GetCollectionFromTransactionId
     *
     * @param string $monduTransactionId
     * @return AbstractDb|AbstractCollection|null
     */
    public function getCollectionFromTransactionId($monduTransactionId)
    {
        $transactionItem = $this->monduTransactionItem->create();

        return $transactionItem->getCollection()
            ->addFieldToFilter('mondu_transaction_id', ['eq' => $monduTransactionId])
            ->load();
    }

    /**
     * CreateTransactionItemsForOrder
     *
     * @param string $monduTransactionId
     * @param Order $order
     * @return void
     * @throws \Exception
     */
    public function createTransactionItemsForOrder($monduTransactionId, $order)
    {
        foreach ($order->getItems() as $orderItem) {
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
