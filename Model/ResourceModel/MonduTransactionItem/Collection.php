<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel\MonduTransactionItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Mondu\Mondu\Model\MonduTransactionItem as MonduTransactionItemModel;
use Mondu\Mondu\Model\ResourceModel\MonduTransactionItem as MonduTransactionItemResource;

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
     * Initializes the model and resource model for the collection.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(MonduTransactionItemModel::class, MonduTransactionItemResource::class);
    }
}
