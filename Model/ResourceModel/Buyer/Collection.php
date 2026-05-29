<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel\Buyer;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Mondu\Mondu\Model\Buyer as BuyerModel;
use Mondu\Mondu\Model\ResourceModel\Buyer as BuyerResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(BuyerModel::class, BuyerResource::class);
    }
}
