<?php
namespace Mondu\Mondu\Model\ResourceModel\MonduTransactionItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mondu_transaction_item_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'mondu_transaction_item_collection';

    /**
     * Define the resource model & the model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Mondu\Mondu\Model\MonduTransactionItem::class,
            \Mondu\Mondu\Model\ResourceModel\MonduTransactionItem::class
        );
    }
}
