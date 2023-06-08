<?php
namespace Mondu\Mondu\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;

class MonduTransactionItem extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Construct
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('mondu_transaction_items', 'entity_id');
    }

    /**
     * Delete records
     *
     * @param string $transactionId
     * @return int
     * @throws LocalizedException
     */
    public function deleteRecords($transactionId)
    {
        $table = $this->getMainTable();
        $where = [];

        $where[] = $this->getConnection()->quoteInto('`mondu_transaction_id` = ?', $transactionId);

        return $this->getConnection()->delete($table, $where);
    }
}
