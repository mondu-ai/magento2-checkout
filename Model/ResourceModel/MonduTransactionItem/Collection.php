<?php
namespace Mondu\Mondu\Model\ResourceModel\MonduTransactionItem;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';
    protected $_eventPrefix = 'mondu_transaction_item_collection';
    protected $_eventObject = 'mondu_transaction_item_collection';

    /**
     * Define the resource model & the model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Mondu\Mondu\Model\MonduTransactionItem', 'Mondu\Mondu\Model\ResourceModel\MonduTransactionItem');
    }
}
