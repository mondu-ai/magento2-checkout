<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Buyer extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mondu_buyers', 'entity_id');
    }
}
