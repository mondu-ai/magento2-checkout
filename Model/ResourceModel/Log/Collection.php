<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel\Log;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Mondu\Mondu\Model\Log as LogModel;
use Mondu\Mondu\Model\ResourceModel\Log as LogResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @var string
     */
    protected $_eventPrefix = 'mondu_mondu_log_collection';

    /**
     * @var string
     */
    protected $_eventObject = 'log_collection';

    /**
     * Initializes the model and resource model for the collection.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(LogModel::class, LogResource::class);
    }
}
