<?php

declare(strict_types=1);

namespace Mondu\Mondu\Cron;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\FollowUpOperations;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Cron
{
    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param BulkActions $bulkActions
     * @param ConfigProvider $configProvider
     * @param FollowUpOperations $followUpOperations
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly BulkActions $bulkActions,
        private readonly ConfigProvider $configProvider,
        private readonly FollowUpOperations $followUpOperations,
    ) {
    }

    /**
     * Executes Mondu bulk shipment action for recently updated orders.
     *
     * @return $this
     */
    public function execute(): self
    {
        if (!$this->configProvider->isCronEnabled()) {
            return $this;
        }

        $date = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $orders = [];
        $collection = $this->orderCollectionFactory->create()
            ->addAttributeToFilter('updated_at', ['from' => $date])
            ->addAttributeToFilter('mondu_reference_id', ['neq' => null])
            ->addFieldToSelect(['entity_id', 'store_id']);

        foreach ($collection as $order) {
            // Stores whose merchant sends invoices to Mondu from elsewhere, e.g. an ERP.
            if ($this->followUpOperations->isDisabledFor($order)) {
                continue;
            }
            $orders[] = $order->getEntityId();
        }

        if (empty($orders)) {
            return $this;
        }

        $this->bulkActions->execute($orders, BulkActions::BULK_SHIP_ACTION);

        return $this;
    }
}
