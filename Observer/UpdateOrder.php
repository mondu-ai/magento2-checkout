<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class UpdateOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected string $name = 'UpdateOrder';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param BulkActions $bulkActions
     * @param ManagerInterface $messageManager
     * @param MonduLogHelper $monduLogHelper
     * @param RequestFactory $requestFactory
     * @param RequestInterface $request
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly BulkActions $bulkActions,
        private readonly ManagerInterface $messageManager,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly RequestFactory $requestFactory,
        private readonly RequestInterface $request,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Sends credit memo or cancel request to Mondu based on refund context.
     *
     * @param Observer $observer
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();
        /** @var OrderInterface $order */
        $order = $creditMemo->getOrder();
        $monduId = $order->getMonduReferenceId();
        $currentState = $order->getState();
        $currentStatus = $order->getStatus();

        $this->monduFileLogger->logOrderStatus('[ORDER STATUS] UpdateOrder observer - START', [
            'order_id' => $order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'current_state' => $currentState,
            'current_status' => $currentStatus,
            'mondu_reference_id' => $monduId,
            'creditmemo_id' => $creditMemo->getEntityId() ?? 'new'
        ]);

        try {
            $canCreditMemo = $order->canCreditmemo()
                || $order->canInvoice()
                || $this->monduLogHelper->canCreditMemo($monduId);
            
            $this->monduFileLogger->logOrderStatus(
                '[ORDER STATUS] UpdateOrder observer - Checking credit memo conditions',
                [
                    'order_id' => $order->getEntityId(),
                    'order_increment_id' => $order->getIncrementId(),
                    'can_creditmemo' => $order->canCreditmemo(),
                    'can_invoice' => $order->canInvoice(),
                    'mondu_can_creditmemo' => $this->monduLogHelper->canCreditMemo($monduId),
                    'will_create_creditmemo' => $canCreditMemo
                ]
            );
            
            if ($canCreditMemo) {
                $this->monduFileLogger
                    ->info('Trying to create a credit memo', ['orderNumber' => $order->getIncrementId()]);
                $requestParams = $this->request->getParams();
                if (isset($requestParams['creditmemo']['creditmemo_mondu_id'])) {
                    $grossAmountCents = round($creditMemo->getBaseGrandTotal(), 2) * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId(),
                    ];

                    $this->monduFileLogger->logOrderStatus(
                        '[ORDER STATUS] UpdateOrder observer - Creating credit memo',
                        [
                            'order_id' => $order->getEntityId(),
                            'order_increment_id' => $order->getIncrementId(),
                            'current_state' => $currentState,
                            'current_status' => $currentStatus,
                            'creditmemo_amount' => $grossAmountCents,
                            'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id']
                        ]
                    );

                    $storeId = (int) $order->getStoreId();
                    $memoData = $this->requestFactory->create(RequestFactory::MEMO, $storeId)->process($data);

                    if (isset($memoData['errors'])) {
                        $this->monduFileLogger->logOrderStatus(
                            '[ORDER STATUS] UpdateOrder observer - Credit memo creation FAILED',
                            [
                                'order_id' => $order->getEntityId(),
                                'order_increment_id' => $order->getIncrementId(),
                                'current_state' => $currentState,
                                'current_status' => $currentStatus,
                                'error' => $memoData['errors'][0]['details'] ?? 'unknown',
                                'reason' => 'Credit memo creation failed in Mondu API'
                            ]
                        );
                        $this->monduFileLogger->info(
                            'Error in UpdateOrder observer ',
                            ['orderNumber' => $order->getIncrementId(), 'e' => $memoData['errors'][0]['details']]
                        );
                        $message = 'Mondu: Unexpected error: Could not send the credit note to Mondu,'
                            . ' please contact Mondu Support to resolve this issue.';
                        $this->messageManager->addErrorMessage($message);
                        return;
                    }

                    $this->monduFileLogger->logOrderStatus(
                        '[ORDER STATUS] UpdateOrder observer - Credit memo created successfully',
                        [
                            'order_id' => $order->getEntityId(),
                            'order_increment_id' => $order->getIncrementId(),
                            'current_state' => $currentState,
                            'current_status' => $currentStatus,
                            'reason' => 'Credit memo created in Mondu, order status may change after sync'
                        ]
                    );
                    $this->monduFileLogger->info(
                        'Created credit memo',
                        ['orderNumber' => $order->getIncrementId()]
                    );

                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger->logOrderStatus(
                        '[ORDER STATUS] UpdateOrder observer - Credit memo creation SKIPPED',
                        [
                            'order_id' => $order->getEntityId(),
                            'order_increment_id' => $order->getIncrementId(),
                            'current_state' => $currentState,
                            'current_status' => $currentStatus,
                            'reason' => 'No Mondu invoice id provided in credit memo request'
                        ]
                    );
                    $this->monduFileLogger->info(
                        'Cant create a credit memo: no Mondu invoice id provided',
                        ['orderNumber' => $order->getIncrementId()]
                    );
                    $logData = $this->monduLogHelper->getTransactionByOrderUid($monduId);
                    $monduState = $logData['mondu_state'] ?? 'unknown';
                    $allowedStates = ['shipped', 'partially_shipped', 'partially_complete', 'complete'];
                    
                    $this->monduFileLogger->logOrderStatus(
                        '[ORDER STATUS] UpdateOrder observer - Checking Mondu state for partial refund',
                        [
                            'order_id' => $order->getEntityId(),
                            'order_increment_id' => $order->getIncrementId(),
                            'mondu_order_state' => $monduState,
                            'allowed_states' => $allowedStates,
                            'can_partially_refund' => in_array($monduState, $allowedStates, true)
                        ]
                    );
                    
                    if (!in_array($monduState, $allowedStates, true)) {
                        $this->monduFileLogger->logOrderStatus(
                            '[ORDER STATUS] UpdateOrder observer - Partial refund NOT ALLOWED',
                            [
                                'order_id' => $order->getEntityId(),
                                'order_increment_id' => $order->getIncrementId(),
                                'current_state' => $currentState,
                                'current_status' => $currentStatus,
                                'mondu_order_state' => $monduState,
                                'reason' => 'Cannot partially refund order before shipment - Mondu state: '
                                    . $monduState
                            ]
                        );
                        throw new LocalizedException(
                            __('Mondu: You cant partially refund order before shipment')
                        );
                    }

                    $message = 'Mondu: Unexpected error: Could not send the credit note to Mondu,'
                        . ' please contact Mondu Support to resolve this issue.';
                    $this->messageManager->addErrorMessage($message);
                }
                return;
            } else {
                $this->monduFileLogger->logOrderStatus(
                    '[ORDER STATUS] UpdateOrder observer - Whole order refund - canceling',
                    [
                        'order_id' => $order->getEntityId(),
                        'order_increment_id' => $order->getIncrementId(),
                        'current_state' => $currentState,
                        'current_status' => $currentStatus,
                        'reason' => 'Whole order amount is being refunded - canceling order in Mondu'
                    ]
                );
                $this->monduFileLogger->info(
                    'Whole order amount is being refunded, canceling the order',
                    ['orderNumber' => $order->getIncrementId()]
                );
                $storeId = (int) $order->getStoreId();
                $cancelData = $this->requestFactory->create(RequestFactory::CANCEL, $storeId)
                    ->process(['orderUid' => $monduId]);

                if (isset($cancelData['errors']) && !isset($cancelData['order'])) {
                    $this->monduFileLogger->logOrderStatus(
                        '[ORDER STATUS] UpdateOrder observer - Cancel FAILED',
                        [
                            'order_id' => $order->getEntityId(),
                            'order_increment_id' => $order->getIncrementId(),
                            'current_state' => $currentState,
                            'current_status' => $currentStatus,
                            'error' => $cancelData['errors'] ?? 'unknown',
                            'reason' => 'Cancel request failed in Mondu API'
                        ]
                    );
                    $message = 'Mondu: Unexpected error: Could not cancel the order,' .
                        ' please contact Mondu Support to resolve this issue.';
                    $this->messageManager->addErrorMessage($message);
                    return;
                }

                $monduCancelState = $cancelData['order']['state'] ?? 'unknown';
                
                $this->monduFileLogger->logOrderStatus(
                    '[ORDER STATUS] UpdateOrder observer - BEFORE cancel status change',
                    [
                        'order_id' => $order->getEntityId(),
                        'order_increment_id' => $order->getIncrementId(),
                        'current_state' => $currentState,
                        'current_status' => $currentStatus,
                        'mondu_order_state_after_cancel' => $monduCancelState,
                        'reason' => 'Whole order amount refunded - canceling order in Mondu'
                    ]
                );

                $this->monduLogHelper->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addCommentToStatusHistory(
                    __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
                );
                $this->orderRepository->save($order);
                
                $this->monduFileLogger->logOrderStatus(
                    '[ORDER STATUS] UpdateOrder observer - AFTER cancel status change',
                    [
                        'order_id' => $order->getEntityId(),
                        'order_increment_id' => $order->getIncrementId(),
                        'previous_state' => $currentState,
                        'previous_status' => $currentStatus,
                        'current_state' => $order->getState(),
                        'current_status' => $order->getStatus(),
                        'mondu_order_state' => $monduCancelState
                    ]
                );
            }
        } catch (Exception $error) {
            $this->monduFileLogger->logOrderStatus('[ORDER STATUS] UpdateOrder observer - EXCEPTION', [
                'order_id' => $order->getEntityId(),
                'order_increment_id' => $order->getIncrementId(),
                'current_state' => $currentState,
                'current_status' => $currentStatus,
                'error' => $error->getMessage(),
                'reason' => 'Exception occurred during order update/refund processing'
            ]);
            $this->monduFileLogger->info(
                'Error in UpdateOrder observer ',
                ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]
            );
            $this->messageManager->addErrorMessage('Mondu: ' . $error->getMessage());
        }
    }
}
