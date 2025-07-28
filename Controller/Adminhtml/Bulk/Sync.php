<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\BulkActions;

class Sync extends BulkAction
{
    /**
     * Executes bulk sync for selected orders.
     *
     * @throws LocalizedException
     * @return void
     */
    public function execute(): void
    {
        $this->executeAction(BulkActions::BULK_SYNC_ACTION);
    }
}
