<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Api\Data\OrderInterface;
use Mondu\Mondu\Model\MonduTransactionItemFactory;

class MonduTransactionItem
{
    /**
     * @param MonduTransactionItemFactory $monduTransactionItem
     */
    public function __construct(private readonly MonduTransactionItemFactory $monduTransactionItem)
    {
    }

    /**
     * Deletes all items linked to a specific transaction.
     *
     * @param int $transactionId
     * @return void
     */
    public function deleteRecords($transactionId): void
    {
        $transactionItem = $this->monduTransactionItem->create();
        $transactionItem->deleteRecordsForTransaction($transactionId);
    }

    /**
     * Returns transaction item collection by transaction ID.
     *
     * @param int $monduTransactionId
     * @throws LocalizedException
     * @return AbstractCollection|AbstractDb|null
     */
    public function getCollectionFromTransactionId(int $monduTransactionId)
    {
        $transactionItem = $this->monduTransactionItem->create();

        return $transactionItem->getCollection()
            ->addFieldToFilter('mondu_transaction_id', ['eq' => $monduTransactionId])
            ->load();
    }

    /**
     * Creates transaction item records based on the order items.
     *
     * @param int $monduTransactionId
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return void
     */
    public function createTransactionItemsForOrder(int $monduTransactionId, OrderInterface $order): void
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
                'product_id' => $variationId,
            ]);
            $transactionItemFactory->save();
        }
    }
}
