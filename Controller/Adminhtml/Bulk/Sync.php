<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Mondu\Mondu\Helpers\BulkActions;

class Sync extends BulkAction
{
    public function execute()
    {
        $this->executeAction(BulkActions::BULK_SYNC_ACTION);
    }
}
