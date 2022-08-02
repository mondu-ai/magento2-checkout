<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

class Sync extends BulkAction
{
    public function execute()
    {
        $this->executeAction('bulkSyncAction');
    }
}
