<?php
namespace Mondu\Mondu\Model\ResourceModel\Log;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';
    protected $_eventPrefix = 'mondu_mondu_log_collection';
    protected $_eventObject = 'log_collection';

    /**
     * Define the resource model & the model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Mondu\Mondu\Model\Log', 'Mondu\Mondu\Model\ResourceModel\Log');
    }
}
