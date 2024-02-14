<?php
namespace Mondu\Mondu\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class ProcessCreditmemoSave
{
    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var Log
     */
    private $monduLogger;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var BulkActions
     */
    private $bulkActions;

    /**
     * @param MonduFileLogger $monduFileLogger
     * @param RequestFactory $requestFactory
     * @param RequestInterface $request
     * @param Log $logger
     * @param BulkActions $bulkActions
     */
    public function __construct(
        MonduFileLogger $monduFileLogger,
        RequestFactory $requestFactory,
        RequestInterface $request,
        Log $logger,
        BulkActions $bulkActions
    ) {
        $this->requestFactory = $requestFactory;
        $this->request = $request;
        $this->monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->bulkActions = $bulkActions;
    }

    /**
     * @param \Magento\Sales\Model\Order\CreditmemoRepository\Interceptor $subject
     * @param $creditmemo
     *
     * @return mixed
     * @throws LocalizedException
     */
    public function afterSave(
        \Magento\Sales\Model\Order\CreditmemoRepository\Interceptor $subject,
        $creditmemo
    ) {
        $order = $creditmemo->getOrder();
        $monduId = $order->getData('mondu_reference_id');

        try {
            if ($order->canCreditmemo() || $order->canInvoice() || $this->monduLogger->canCreditMemo($monduId)) {
                $this->monduFileLogger
                    ->info('Trying to create a credit memo', ['orderNumber' => $order->getIncrementId()]);
                $requestParams = $this->request->getParams();
                if (isset($requestParams['creditmemo']['creditmemo_mondu_id'])) {
                    $grossAmountCents = round($creditmemo->getBaseGrandTotal(), 2) * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditmemo->getIncrementId()
                    ];

                    $memoData = $this->requestFactory->create(RequestFactory::MEMO)
                                                     ->process($data);

                    if (isset($memoData['errors'])) {
                        $this->monduFileLogger
                            ->info(
                                'Error in UpdateOrder observer ',
                                ['orderNumber' => $order->getIncrementId(), 'e' => $memoData['errors'][0]['details']]
                            );
                        throw new LocalizedException(__('Mondu: Unexpected error: Could not send the credit note to Mondu,' .
                                                        ' please contact Mondu Support to resolve this issue.'));
                    }

                    $this->monduFileLogger->info('Created credit memo', ['orderNumber' => $order->getIncrementId()]);
                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger
                        ->info(
                            'Cant create a credit memo: no Mondu invoice id provided',
                            ['orderNumber' => $order->getIncrementId()]
                        );
                    $logData = $this->monduLogger->getTransactionByOrderUid($monduId);

                    if ($logData['mondu_state']  !== 'shipped' &&
                        $logData['mondu_state'] !== 'partially_shipped' &&
                        $logData['mondu_state'] !== 'partially_complete' &&
                        $logData['mondu_state'] !== 'complete'
                    ) {
                        throw new LocalizedException(__('Mondu: You cant partially refund order before shipment'));
                    }
                }

                return $creditmemo;
            } else {
                $this->monduFileLogger
                    ->info(
                        'Whole order amount is being refunded, canceling the order',
                        ['orderNumber' => $order->getIncrementId()]
                    );
                $cancelData = $this->requestFactory->create(RequestFactory::CANCEL)
                                                   ->process(['orderUid' => $monduId]);

                if (isset($cancelData['errors']) && !isset($cancelData['order'])) {
                    throw new LocalizedException(__('Mondu: Unexpected error: Could not cancel the order,' .
                                                    ' please contact Mondu Support to resolve this issue.'));
                }

                $this->monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(
                    __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
                );
                $order->save();
            }
        } catch (\Exception $error) {
            $this->monduFileLogger
                ->info(
                    'Error in UpdateOrder observer ',
                    ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]
                );

            throw new LocalizedException(__('Mondu: ' . $error->getMessage()));
        }

        return $creditmemo;
    }
}
