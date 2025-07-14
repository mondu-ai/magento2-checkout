<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\Logger\Logger;

abstract class BulkAction extends Action
{
    use BulkActionHelpers;

    public const ADMIN_RESOURCE = 'Magento_Sales::sales_order';

    /**
     * @param Context $context
     * @param BulkActions $bulkActions
     * @param Filter $filter
     * @param Logger $monduFileLogger
     * @param OrderCollectionFactory $orderCollectionFactory
     */
    public function __construct(
        Context $context,
        protected BulkActions $bulkActions,
        protected Filter $filter,
        protected Logger $monduFileLogger,
        protected OrderCollectionFactory $orderCollectionFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Executes the controller action.
     *
     * @throws LocalizedException
     */
    abstract public function execute();
}
