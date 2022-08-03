<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;

trait BulkActionHelpers {
    public function bulkActionResponse($success, $incorrect, $failed): ResponseInterface
    {
        if(!empty($success)) {
            $this->getMessageManager()->addSuccessMessage('Mondu: Processed '. count($success). ' orders - '. join(', ', $success));
        }
        if(!empty($incorrect)) {
            $this->getMessageManager()->addErrorMessage('Mondu: '.count($incorrect). ' order(s) were placed using different payment method. orders - [ '. join(', ', $incorrect). ' ]');
        }

        if(!empty($failed)) {
            $this->getMessageManager()->addErrorMessage('Mondu: '.count($failed). ' order(s) failed, please check debug logs for more info. orders - [ '. join(', ', $failed). ' ]');
        }

        return $this->_redirect('sales/order/index');
    }

    /**
     * @throws LocalizedException
     */
    public function getResourceIds($action): array
    {
        $collection = $this->filter->getCollection($this->orderCollectionFactory->create());
        $orderIds =  $collection->getAllIds();
        $this->monduFileLogger->info("$action : Got ids ", ['orderIds' => $orderIds]);

        return $orderIds;
    }

    /**
     * @throws LocalizedException
     */
    public function executeAction($action, $additionalData = null) {
        $orderIds = $this->getResourceIds($action);

        [$success, $incorrect, $failed] = $this->bulkActions->execute($orderIds, $action, $additionalData);

        $this->bulkActionResponse($success, $incorrect, $failed);
    }
}
