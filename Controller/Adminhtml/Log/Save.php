<?php

namespace Mondu\Mondu\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Save extends \Magento\Backend\App\Action
{
    const ADMIN_RESOURCE = 'Mondu_Mondu::log';
    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    protected $requestFactory;
    private $monduLogger;
    private $orderRepository;

    /**
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param ImageUploader $imageUploader
     */
    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->dataPersistor = $dataPersistor;
        $this->requestFactory = $requestFactory;
        $this->_monduLogger = $logger;
        $this->orderRepository = $orderRepository;
        parent::__construct($context);
    }

    /**
     * Save action
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        $requestObject = [
            'orderUid' => $data['reference_id'],
            'state' => $data['mondu_state']
        ];

        $response = $this->requestFactory->create(RequestFactory::ADJUST_ORDER)
            ->process($requestObject);
        if(@$response['status'] === 422) {
            $this->messageManager->addError($response['errors'][0]['name']. ' '. $response['errors'][0]['details']);
            $this->_monduLogger->syncOrder($data['reference_id']);
            return $resultRedirect->setPath('*/*/adjust', ['entity_id' => $this->getRequest()->getParam('entity_id')]);
        }
        if(@$response['order']['state'] === 'canceled') {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_CANCELED)->save();
        } elseif (@$response['order']['state'] === 'shipped') {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_COMPLETE)->save();
        }

        $this->_monduLogger->syncOrder($data['reference_id']);

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Authorization level of a basic admin session
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
