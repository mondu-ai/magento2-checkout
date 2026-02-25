<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CancelOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected string $name = 'CancelOrder';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param ManagerInterface $messageManager
     * @param RequestFactory $requestFactory
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly ManagerInterface $messageManager,
        private readonly RequestFactory $requestFactory,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Cancels the order in Mondu if eligible.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();
        $orderIncrementId = $order->getIncrementId();
        $monduId = $order->getMonduReferenceId();
        $currentState = $order->getState();
        $currentStatus = $order->getStatus();

        try {
            if ($order->getRelationChildId()) {
                $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - SKIPPED', [
                    'order_id' => $order->getEntityId(),
                    'order_increment_id' => $orderIncrementId,
                    'current_state' => $currentState,
                    'current_status' => $currentStatus,
                    'reason' => 'Order has child relation, skipping cancellation'
                ]);
                return;
            }

            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - START', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $orderIncrementId,
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'mondu_reference_id' => $monduId
            ]);

            $this->monduFileLogger->info('Trying to cancel Order ' . $orderIncrementId);

            $storeId = (int) $order->getStoreId();
            $cancelData = $this->requestFactory->create(RequestFactory::CANCEL, $storeId)
                ->process(['orderUid' => $monduId]);
            if (!$cancelData) {
                $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - FAILED', [
                    'order_id' => $order->getEntityId(),
                    'order_increment_id' => $orderIncrementId,
                    'current_state' => $currentState,
                    'current_status' => $currentStatus,
                    'reason' => 'Cancel request returned no data - order not found in Mondu'
                ]);
                $this->messageManager->addErrorMessage(
                    'Mondu: Unexpected error: Order could not be found,'
                    . ' please contact Mondu Support to resolve this issue.'
                );
                return;
            }

            $monduCancelState = $cancelData['order']['state'] ?? 'unknown';
            
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - BEFORE status change', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $orderIncrementId,
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'mondu_order_state_after_cancel' => $monduCancelState,
                'reason' => 'Order cancellation successful in Mondu, updating order status'
            ]);

            $order->addCommentToStatusHistory(
                __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
            );
            $this->orderRepository->save($order);
            
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - AFTER status change', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $orderIncrementId,
                'previous_state' => $currentState,
                'previous_status' => $currentStatus,
                'current_state' => $order->getState(),
                'current_status' => $order->getStatus(),
                'mondu_order_state' => $monduCancelState
            ]);
            
            $this->monduFileLogger->info('Cancelled order ', ['orderNumber' => $orderIncrementId]);
        } catch (Exception $error) {
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] CancelOrder observer - EXCEPTION', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $orderIncrementId,
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'error' => $error->getMessage(),
                'reason' => 'Exception occurred during order cancellation'
            ]);
            $this->monduFileLogger->info(
                'Failed to cancel Order ' . $orderIncrementId,
                ['e' => $error->getMessage()]
            );
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
