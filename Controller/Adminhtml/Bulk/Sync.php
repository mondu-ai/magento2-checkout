<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\BulkActions;

class Sync extends BulkAction
{
    /**
     * Syncs selected orders
     *
     * @return void
     * @throws LocalizedException
     */
    public function execute()
    {
        $this->executeAction(BulkActions::BULK_SYNC_ACTION);
    }
}
