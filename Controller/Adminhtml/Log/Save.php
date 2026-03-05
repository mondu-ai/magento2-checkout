<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mondu_Mondu::log';

    /**
     * @param Context $context
     * @param MonduLogHelper $monduLogHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param RequestFactory $requestFactory
     * @param MonduFileLogger $monduFileLogger
     */
    public function __construct(
        Context $context,
        protected MonduLogHelper $monduLogHelper,
        protected OrderRepositoryInterface $orderRepository,
        protected RequestFactory $requestFactory,
        protected MonduFileLogger $monduFileLogger,
    ) {
        parent::__construct($context);
    }

    /**
     * Sends adjust request to Mondu and updates order status or shows error on failure.
     *
     * @throws LocalizedException
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        $requestObject = ['orderUid' => $data['reference_id'], 'state' => $data['mondu_state']];
        $storeId = null;
        if (!empty($data['order_id'])) {
            try {
                $order = $this->orderRepository->get($data['order_id']);
                $storeId = (int) $order->getStoreId();
            } catch (\Exception $e) {
                $this->monduFileLogger->warning('Could not load order to resolve store ID', [
                    'order_id' => $data['order_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $response = $this->requestFactory->create(RequestFactory::ADJUST_ORDER, $storeId)->process($requestObject);
        if (isset($response['status']) && $response['status'] === 422) {
            $this->messageManager->addErrorMessage(
                $response['errors'][0]['name'] . ' ' . $response['errors'][0]['details']
            );
            $this->monduLogHelper->syncOrder($data['reference_id']);
            return $resultRedirect->setPath(
                '*/*/adjust',
                ['entity_id' => $this->getRequest()->getParam('entity_id')]
            );
        }

        if (isset($response['order']['state']) && $response['order']['state'] === OrderHelper::CANCELED) {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($order);
        } elseif (isset($response['order']['state']) && $response['order']['state'] === OrderHelper::SHIPPED) {
            $order = $this->orderRepository->get($data['order_id']);
            $order->setStatus(Order::STATE_COMPLETE);
            $this->orderRepository->save($order);
        }

        $this->monduLogHelper->syncOrder($data['reference_id']);

        return $resultRedirect->setPath('*/*/');
    }
}
