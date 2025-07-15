<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Log extends AbstractDb
{
    /**
     * Initializes the main table and primary key field.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mondu_transactions', 'entity_id');
    }
}
