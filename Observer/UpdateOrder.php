<?php
namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Magento\Framework\Message\ManagerInterface;

class UpdateOrder extends MonduObserver
{
    protected $name = 'UpdateOrder';

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

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
     * @var BulkActions
     */
    private $bulkActions;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @param PaymentMethod $paymentMethodHelper
     * @param MonduFileLogger $monduFileLogger
     * @param ContextHelper $contextHelper
     * @param RequestFactory $requestFactory
     * @param RequestInterface $request
     * @param Log $logger
     * @param BulkActions $bulkActions
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        PaymentMethod $paymentMethodHelper,
        MonduFileLogger $monduFileLogger,
        ContextHelper $contextHelper,
        RequestFactory $requestFactory,
        RequestInterface $request,
        Log $logger,
        BulkActions $bulkActions,
        ManagerInterface $messageManager
    ) {
        parent::__construct(
            $paymentMethodHelper,
            $monduFileLogger,
            $contextHelper
        );

        $this->requestFactory = $requestFactory;
        $this->request = $request;
        $this->monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->bulkActions = $bulkActions;
        $this->messageManager = $messageManager;
    }

    //TODO refactor
    public function _execute(Observer $observer)
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();
        $order = $creditMemo->getOrder();
        $monduId = $order->getData('mondu_reference_id');

        try {
            if ($order->canCreditmemo() || $order->canInvoice() || $this->monduLogger->canCreditMemo($monduId)) {
                $this->monduFileLogger->info('Trying to create a credit memo', ['orderNumber' => $order->getIncrementId()]);
                $requestParams = $this->request->getParams();
                if (@$requestParams['creditmemo']['creditmemo_mondu_id']) {
                    $grossAmountCents = round($creditMemo->getBaseGrandTotal(), 2) * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId()
                    ];

                    $memoData = $this->requestFactory->create(RequestFactory::MEMO)
                        ->process($data);

                    if (@$memoData['errors']) {
                        $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $memoData['errors'][0]['details']]);
                        $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not send the credit note to Mondu, please contact Mondu Support to resolve this issue.');
                        return;
                    }

                    $this->monduFileLogger->info('Created credit memo', ['orderNumber' => $order->getIncrementId()]);

                    $this->bulkActions->execute([$order->getId()], BulkActions::BULK_SYNC_ACTION);
                } else {
                    $this->monduFileLogger->info('Cant create a credit memo: no Mondu invoice id provided', ['orderNumber' => $order->getIncrementId()]);
                    $logData = $this->monduLogger->getTransactionByOrderUid($monduId);
                    if (
                        $logData['mondu_state']  !== 'shipped' &&
                        $logData['mondu_state'] !== 'partially_shipped' &&
                        $logData['mondu_state'] !== 'partially_complete' &&
                        $logData['mondu_state'] !== 'complete'
                    ) {
                        throw new LocalizedException(__('Mondu: You cant partially refund order before shipment'));
                    }

                    $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not send the credit note to Mondu, please contact Mondu Support to resolve this issue.');
                }
                return;
            } else {
                $this->monduFileLogger->info('Whole order amount is being refunded, canceling the order', ['orderNumber' => $order->getIncrementId()]);
                $cancelData = $this->requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if (@$cancelData['errors'] && !@$cancelData['order']) {
                    $this->messageManager->addErrorMessage('Mondu: Unexpected error: Could not cancel the order, please contact Mondu Support to resolve this issue.');
                    return;
                }

                $this->monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(__('Mondu: The order with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }
        } catch (Exception $error) {
            $this->monduFileLogger->info('Error in UpdateOrder observer ', ['orderNumber' => $order->getIncrementId(), 'e' => $error->getMessage()]);
            $this->messageManager->addErrorMessage('Mondu: ' . $error->getMessage());
        }
    }
}
