<?php

namespace Mondu\Mondu\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Helpers\OrderHelper;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'Mondu_Mondu::log';

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @var Log
     */
    private $monduLogger;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param RequestFactory $requestFactory
     * @param Log $logger
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        RequestFactory $requestFactory,
        Log $logger,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->dataPersistor = $dataPersistor;
        $this->requestFactory = $requestFactory;
        $this->monduLogger = $logger;
        $this->orderRepository = $orderRepository;
        parent::__construct($context);
    }

    /**
     * Save action
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws LocalizedException
     * @throws \Exception
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
        if (isset($response['status']) && $response['status'] === 422) {
            $this->messageManager->addError($response['errors'][0]['name']. ' '. $response['errors'][0]['details']);
            $this->monduLogger->syncOrder($data['reference_id']);
            return $resultRedirect->setPath('*/*/adjust', ['entity_id' => $this->getRequest()->getParam('entity_id')]);
        }
        if (isset($response['order']['state']) && $response['order']['state'] === OrderHelper::CANCELED) {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_CANCELED)->save();
        } elseif (isset($response['order']['state']) && $response['order']['state'] === OrderHelper::SHIPPED) {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_COMPLETE)->save();
        }

        $this->monduLogger->syncOrder($data['reference_id']);

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
