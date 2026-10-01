<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Links a Mondu order to the quote it was created from, so the Magento order can be placed
 * server side when the buyer never comes back from the hosted checkout.
 */
class PendingCheckout extends AbstractDb
{
    /**
     * Initializes the main table and primary key field.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mondu_pending_checkouts', 'entity_id');
    }

    /**
     * Stores the link between a Mondu order and a quote.
     *
     * @param string $orderUuid
     * @param int $quoteId
     * @param int|null $storeId
     * @return void
     */
    public function register(string $orderUuid, int $quoteId, ?int $storeId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            ['order_uuid' => $orderUuid, 'quote_id' => $quoteId, 'store_id' => $storeId],
            ['quote_id', 'store_id']
        );
    }

    /**
     * Returns the link for a Mondu order, or null if none was stored.
     *
     * @param string $orderUuid
     * @return array|null
     */
    public function getByOrderUuid(string $orderUuid): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable())
                ->where('order_uuid = ?', $orderUuid)
        );

        return $row ?: null;
    }

    /**
     * Marks the link as handled, so the cron stops looking at it.
     *
     * @param string $orderUuid
     * @return void
     */
    public function markProcessed(string $orderUuid): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['processed_at' => gmdate('Y-m-d H:i:s')],
            ['order_uuid = ?' => $orderUuid, 'processed_at IS NULL']
        );
    }

    /**
     * Returns unprocessed links created between $maxAgeMinutes and $minAgeMinutes ago.
     *
     * @param int $minAgeMinutes
     * @param int $maxAgeMinutes
     * @param int $limit
     * @return array
     */
    public function getUnprocessed(int $minAgeMinutes, int $maxAgeMinutes, int $limit): array
    {
        $connection = $this->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->getMainTable())
                ->where('processed_at IS NULL')
                ->where('created_at <= ?', gmdate('Y-m-d H:i:s', time() - $minAgeMinutes * 60))
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - $maxAgeMinutes * 60))
                ->order('created_at ASC')
                ->limit($limit)
        );
    }

    /**
     * Deletes links older than the given number of days.
     *
     * @param int $days
     * @return void
     */
    public function deleteOlderThan(int $days): void
    {
        $this->getConnection()->delete(
            $this->getMainTable(),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }
}
