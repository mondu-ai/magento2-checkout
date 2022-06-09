<?php

namespace Mondu\Mondu\Controller\Adminhtml\Bulk;

use Magento\Backend\App\Action;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mondu\Mondu\Helpers\BulkActions;

class Ship extends Action
{
    const ADMIN_RESOURCE = 'Magento_Sales::sales_order';

    /**
     * @var BulkActions
     */
    private $bulkActions;

    /**
     * @var OrderCollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * ChangeColor constructor.
     * @param Action\Context $context
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param BulkActions $bulkActions
     */
    public function __construct(
        Action\Context $context,
        OrderCollectionFactory $orderCollectionFactory,
        BulkActions $bulkActions
    ) {
        $this->bulkActions = $bulkActions;
        $this->orderCollectionFactory = $orderCollectionFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();

        $orderIds = $request->getPost('selected', []);

        [$success, $incorrect, $failed] = $this->bulkActions->bulkShip($orderIds);

        if(!empty($success)) {
            $this->getMessageManager()->addSuccessMessage('Mondu: Processed '. count($success). ' orders - '. join($success, ', '));
        }
        if(!empty($incorrect)) {
            $this->getMessageManager()->addErrorMessage('Mondu: '.count($incorrect). ' order(s) were placed using different payment method. orders - [ '. join($incorrect, ', '). ' ]');
        }

        if(!empty($failed)) {
            $this->getMessageManager()->addErrorMessage('Mondu: '.count($failed). ' order(s) failed to create invoice, please check debug logs for more info. orders - [ '. join($failed, ', '). ' ]');
        }

        return $this->_redirect('sales/order/index');
    }
}