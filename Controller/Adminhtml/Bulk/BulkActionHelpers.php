<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;

trait BulkActionHelpers
{
    /**
     * Adds result messages to message manager.
     *
     * @param array $success
     * @param array $incorrect
     * @param array $failed
     * @return ResponseInterface
     */
    public function bulkActionResponse(array $success, array $incorrect, array $failed): ResponseInterface
    {
        if (!empty($success)) {
            $this->getMessageManager()->addSuccessMessage(
                'Mondu: Processed ' . count($success) . ' orders - ' . join(', ', $success)
            );
        }

        if (!empty($incorrect)) {
            $this->getMessageManager()->addErrorMessage(
                'Mondu: '
                . count($incorrect)
                . ' order(s) were placed using different payment method. orders - [ '
                . join(', ', $incorrect) . ' ]'
            );
        }

        if (!empty($failed)) {
            $this->getMessageManager()->addErrorMessage(
                'Mondu: '
                . count($failed)
                . ' order(s) failed, please check debug logs for more info. orders - [ '
                . join(', ', $failed) . ' ]'
            );
        }

        return $this->_redirect('sales/order/index');
    }

    /**
     * Returns selected order IDs from mass action filter.
     *
     * @param string $action
     * @throws LocalizedException
     * @return array
     */
    public function getResourceIds(string $action): array
    {
        $orderIds = $this->filter->getCollection($this->orderCollectionFactory->create())->getAllIds();
        $this->monduFileLogger->info("{$action} : Got ids ", ['orderIds' => $orderIds]);

        return $orderIds;
    }

    /**
     * Executes the specified bulk action on selected orders.
     *
     * @param string $action
     * @param array $additionalData
     * @throws LocalizedException
     * @return void
     */
    public function executeAction(string $action, array $additionalData = []): void
    {
        $orderIds = $this->getResourceIds($action);

        [$success, $incorrect, $failed] = $this->bulkActions->execute($orderIds, $action, $additionalData);

        $this->bulkActionResponse($success, $incorrect, $failed);
    }
}
