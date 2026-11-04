<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\AdminOrder;
use Mondu\Mondu\Helpers\BackendOrders;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Setup\Patch\Data\PendingBuyerConfirmationStatus;

class CreateAsyncOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected string $name = 'CreateAsyncOrder';

    /**
     * @param AdminOrder $adminOrder
     * @param BackendOrders $backendOrders
     * @param ContextHelper $contextHelper
     * @param CustomerRepositoryInterface $customerRepository
     * @param MonduFileLogger $monduFileLogger
     * @param MonduLogHelper $monduLogHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        private readonly AdminOrder $adminOrder,
        private readonly BackendOrders $backendOrders,
        ContextHelper $contextHelper,
        private readonly CustomerRepositoryInterface $customerRepository,
        MonduFileLogger $monduFileLogger,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly OrderRepositoryInterface $orderRepository,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Creates a Mondu async order when a merchant places an order on behalf of a buyer in admin.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();

        if (!$this->adminOrder->isNewAdminOrder($order)) {
            // A storefront order, or an admin edit, which CreateOrder adjusts on
            // the Mondu order it replaces instead of creating a second one.
            $this->monduFileLogger->info('CreateAsyncOrder: not a new admin order, skipping', [
                'orderNumber' => $order->getIncrementId(),
            ]);
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!$this->backendOrders->isActive($storeId)) {
            $this->monduFileLogger->info('CreateAsyncOrder: backend orders are disabled, skipping', [
                'orderNumber' => $order->getIncrementId(),
            ]);
            return;
        }

        try {
            $result = $this->requestFactory
                ->create(RequestFactory::CREATE_ASYNC_ORDER, $storeId)
                ->process(['order' => $order]);

            $orderData = $result['order'];
            $orderUuid = $orderData['uuid'];

            $order->setData('mondu_reference_id', $orderUuid);
            $order->addCommentToStatusHistory(__('Mondu: async order created, uuid %1', $orderUuid));
            $this->orderRepository->save($order);

            $this->monduLogHelper->logTransaction(
                $order,
                $orderData,
                null,
                $this->paymentMethodHelper->getCode($order->getPayment()),
                'async'
            );

            $this->saveBuyerUuidIfPresent($order, $orderData);

            $this->monduFileLogger->info('CreateAsyncOrder: order created successfully', [
                'orderNumber' => $order->getIncrementId(),
                'mondu_uuid'  => $orderUuid,
                'mondu_state' => $orderData['state'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            $this->monduFileLogger->error('CreateAsyncOrder: failed to create Mondu order', [
                'orderNumber' => $order->getIncrementId(),
                'error'       => $e->getMessage(),
            ]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }

    /**
     * Saves buyer UUID to the customer entity if present in the Mondu response.
     *
     * @param OrderInterface $order
     * @param array $orderData
     * @return void
     */
    private function saveBuyerUuidIfPresent(OrderInterface $order, array $orderData): void
    {
        $buyerUuid = $orderData['buyer']['uuid'] ?? null;
        $customerId = $order->getCustomerId();

        if (!$buyerUuid || !$customerId) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById((int) $customerId);
            $existing = $customer->getCustomAttribute('mondu_buyer_uuid');

            if (!$existing || !$existing->getValue()) {
                $customer->setCustomAttribute('mondu_buyer_uuid', $buyerUuid);
                $this->customerRepository->save($customer);

                $this->monduFileLogger->info('CreateAsyncOrder: buyer_uuid saved to customer', [
                    'customer_id' => $customerId,
                    'buyer_uuid'  => $buyerUuid,
                ]);
            }
        } catch (Exception $e) {
            $this->monduFileLogger->warning('CreateAsyncOrder: could not save buyer_uuid', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
