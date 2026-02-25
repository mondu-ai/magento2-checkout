<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\MonduTransactionItem;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected string $name = 'CreateOrder';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param CheckoutSession $checkoutSession
     * @param MonduLogHelper $monduLogHelper
     * @param MonduTransactionItem $monduTransactionItem
     * @param OrderHelper $orderHelper
     * @param RequestFactory $requestFactory
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly CheckoutSession $checkoutSession,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly MonduTransactionItem $monduTransactionItem,
        private readonly OrderHelper $orderHelper,
        private readonly RequestFactory $requestFactory,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Creates or adjusts a Mondu order based on order context.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        $orderUid = $this->checkoutSession->getMonduid();
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $createMonduDatabaseRecord = true;

        $isEditOrder = $order->getRelationParentRealId() || $order->getRelationParentId();
        $isMondu = $this->paymentMethodHelper->isMondu($payment);

        if ($isEditOrder && !$isMondu) {
            // checks if order with Mondu payment method was changed to other payment method and cancels Mondu order.
            $this->orderHelper->handlePaymentMethodChange($order);
        }

        if (!$isMondu) {
            $this->monduFileLogger->info(
                'Not a Mondu order, skipping',
                ['orderNumber' => $order->getIncrementId()]
            );
            return;
        }

        if ($isEditOrder) {
            $this->monduFileLogger
                ->info(
                    'Order has parent id, adjusting order in Mondu. ',
                    ['orderNumber' => $order->getIncrementId()]
                );
            $this->orderHelper->handleOrderAdjustment($order);
            $orderUid = $order->getMonduReferenceId();
            $createMonduDatabaseRecord = false;
        }

        try {
            $storeId = (int) $order->getStoreId();
            $orderData = $this->requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD, $storeId)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];

            $authorizationData = $this->confirmAuthorizedOrder($orderData, $order->getIncrementId(), (int) $order->getStoreId());
            $orderData['state'] = $authorizationData['state'];

            $order->setData('mondu_reference_id', $orderUid);
            $order->addCommentToStatusHistory(__('Mondu: order id %1', $orderData['uuid']));
            $this->orderRepository->save($order);

            if ($createMonduDatabaseRecord) {
                $this->monduLogHelper
                    ->logTransaction($order, $orderData, null, $this->paymentMethodHelper->getCode($payment));
            } else {
                $transactionId = $this->monduLogHelper
                    ->updateLogMonduData($orderUid, null, null, null, $order->getId());

                $this->monduTransactionItem->deleteRecords($transactionId);
                $this->monduTransactionItem->createTransactionItemsForOrder((int) $transactionId, $order);
            }
        } catch (Exception $e) {
            $this->monduFileLogger->error('Error in CreateOrder observer', [
                'orderNumber' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }

    /**
     * Confirms authorized Mondu order if applicable.
     *
     * @param array $orderData
     * @param string $orderNumber
     * @param int $storeId
     * @throws LocalizedException
     * @return array
     */
    protected function confirmAuthorizedOrder(array $orderData, string $orderNumber, int $storeId): array
    {
        if ($orderData['state'] === OrderHelper::AUTHORIZED || $orderData['state'] === 'pending') {
            $authorizationData = $this->requestFactory->create(RequestFactory::CONFIRM_ORDER, $storeId)
                ->process(['orderUid' => $orderData['uuid'], 'referenceId' => $orderNumber]);

            return $authorizationData['order'];
        }

        return $orderData;
    }
}
