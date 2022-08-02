<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

class Ship extends BulkAction
{
    public function execute()
    {
        $this->executeAction('bulkShipAction', ['withLineItems' => false]);
    }
}
