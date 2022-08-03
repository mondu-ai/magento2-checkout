<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Mondu\Mondu\Helpers\BulkActions;

class Ship extends BulkAction
{
    public function execute()
    {
        $this->executeAction(BulkActions::BULK_SHIP_ACTION);
    }
}
