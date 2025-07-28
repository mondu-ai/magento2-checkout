<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class MonduTransactionItem extends AbstractDb
{
    /**
     * Initializes the main table and primary key field.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mondu_transaction_items', 'entity_id');
    }

    /**
     * Deletes all records related to the given Mondu transaction ID.
     *
     * @param string $transactionId
     * @throws LocalizedException
     * @return int
     */
    public function deleteRecords($transactionId)
    {
        $table = $this->getMainTable();
        $where = [];

        $where[] = $this->getConnection()->quoteInto('`mondu_transaction_id` = ?', $transactionId);

        return $this->getConnection()->delete($table, $where);
    }
}
