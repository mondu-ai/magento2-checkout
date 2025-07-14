<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class UpdateOrder extends MonduObserver
{
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
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Execute.
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

        try {
            if ($order->canCreditmemo() || $order->canInvoice() || $this->monduLogHelper->canCreditMemo($monduId)) {
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

                    $memoData = $this->requestFactory->create(RequestFactory::MEMO)
                        ->process($data);

                    if (isset($memoData['errors'])) {
                        $this->monduFileLogger
                            ->info(
                                'Error in UpdateOrder observer ',
                                ['orderNumber' => $order->getIncrementId(), 'e' => $memoData['errors'][0]['details']]
                            );
                        $message = 'Mondu: Unexpected error: Could not send the credit note to Mondu,'
                            . ' please contact Mondu Support to resolve this issue.';
                        $this->messageManager
                            ->addErrorMessage($message);
                        return;
                    }

                    $this->monduFileLogger->info(
                        'Created credit memo',
                        ['orderNumber' => $order->getIncrementId()]
                    );

                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger
                        ->info(
                            'Cant create a credit memo: no Mondu invoice id provided',
                            ['orderNumber' => $order->getIncrementId()]
                        );
                    $logData = $this->monduLogHelper->getTransactionByOrderUid($monduId);
                    if ($logData['mondu_state'] !== 'shipped'
                        && $logData['mondu_state'] !== 'partially_shipped'
                        && $logData['mondu_state'] !== 'partially_complete'
                        && $logData['mondu_state'] !== 'complete'
                    ) {
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
                $this->monduFileLogger->info(
                    'Whole order amount is being refunded, canceling the order',
                    ['orderNumber' => $order->getIncrementId()]
                );
                $cancelData = $this->requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if (isset($cancelData['errors']) && !isset($cancelData['order'])) {
                    $message = 'Mondu: Unexpected error: Could not cancel the order,' .
                        ' please contact Mondu Support to resolve this issue.';
                    $this->messageManager->addErrorMessage($message);
                    return;
                }

                $this->monduLogHelper->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addCommentToStatusHistory(
                    __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
                );
                $order->save();
            }
        } catch (Exception $error) {
            $this->monduFileLogger->info(
                'Error in UpdateOrder observer ',
                ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]
            );
            $this->messageManager->addErrorMessage('Mondu: ' . $error->getMessage());
        }
    }
}
