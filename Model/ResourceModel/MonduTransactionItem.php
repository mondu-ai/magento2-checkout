<?php
namespace Mondu\Mondu\Model\ResourceModel;

class MonduTransactionItem extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context
    )
    {
        parent::__construct($context);
    }

    protected function _construct()
    {
        $this->_init('mondu_transaction_items', 'entity_id');
    }

    public function deleteRecords($transactionId) {
        $table = $this->getMainTable();
        $where = [];
        
        $where[] = $this->getConnection()->quoteInto('`mondu_transaction_id` = ?',$transactionId);

        $result = $this->getConnection()->delete($table, $where);
        return $result;
    }
}
