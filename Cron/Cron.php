<?php

namespace Mondu\Mondu\Cron;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Cron
{

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var BulkActions
     */
    private $bulkActions;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param BulkActions $bulkActions
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        BulkActions $bulkActions,
        ConfigProvider $configProvider
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->bulkActions = $bulkActions;
        $this->configProvider = $configProvider;
    }

    /**
     * Execute
     *
     * @return $this
     */
    public function execute(): Cron
    {
        if ($this->configProvider->isCronEnabled()) {
            $date = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $result = $this->orderCollectionFactory->create()
                ->addAttributeToFilter('updated_at', [ 'from' => $date ])
                ->addAttributeToFilter('mondu_reference_id', ['neq' => null]);

            $orders = $result->getAllIds();

            $this->bulkActions->execute($orders, BulkActions::BULK_SHIP_ACTION);
        }
        return $this;
    }
}
