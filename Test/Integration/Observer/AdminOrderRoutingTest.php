<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event;
use Magento\Framework\Event\Config\Reader as EventConfigReader;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Mondu\Mondu\Helpers\AdminOrder;
use Mondu\Mondu\Helpers\BackendOrders;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\MonduTransactionItem;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Checkout\OrderUuidContext;
use Mondu\Mondu\Model\Request\Confirm;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Request\RequestInterface as MonduRequestInterface;
use Mondu\Mondu\Observer\AfterPlaceOrder;
use Mondu\Mondu\Observer\CreateAsyncOrder;
use Mondu\Mondu\Observer\CreateOrder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: which observer handles an order placed in the admin.
 *
 * A new admin order goes to Mondu through create_async only. An admin edit of a
 * Mondu order replaces the order, and the checkout observers must adjust the
 * Mondu order, or cancel it when the edit moved to another payment method,
 * instead of leaving it live or creating a second one.
 */
#[AllowMockObjectsWithoutExpectations]
class AdminOrderRoutingTest extends TestCase
{
    private const MONDU_ID = 'c1b2a3d4-1111-4a4a-8b8b-0c0c0c0c0c0c';

    /**
     * Mondu request types asked for, in order.
     *
     * @var string[]
     */
    private array $requestedTypes = [];

    /**
     * Calls into OrderHelper, in order.
     *
     * @var string[]
     */
    private array $orderHelperCalls = [];

    protected function setUp(): void
    {
        $this->requestedTypes = [];
        $this->orderHelperCalls = [];
    }

    public function testCheckoutObserversAreRegisteredForEveryArea(): void
    {
        $global = ObjectManager::getInstance()->get(EventConfigReader::class)->read('global');

        $this->assertArrayHasKey(
            'sales_order_place_before_mondu',
            $global['sales_order_place_before'] ?? [],
            'admin edits, REST and GraphQL placement need CreateOrder too'
        );
        $this->assertArrayHasKey('sales_order_place_after_mondu', $global['sales_order_place_after'] ?? []);
    }

    public function testAdminEditAdjustsTheMonduOrder(): void
    {
        $order = $this->createOrder('mondu', true);

        $this->createOrderObserver()->execute($this->observerFor($order));

        $this->assertSame(['handleOrderAdjustment'], $this->orderHelperCalls);
        $this->assertNotContains(RequestFactory::CREATE_ASYNC_ORDER, $this->requestedTypes);
    }

    public function testAdminEditToAnotherPaymentMethodCancelsTheMonduOrder(): void
    {
        $order = $this->createOrder('checkmo', true);

        $this->createOrderObserver()->execute($this->observerFor($order));

        $this->assertSame(['handlePaymentMethodChange'], $this->orderHelperCalls);
    }

    public function testAdminEditDoesNotCreateASecondMonduOrder(): void
    {
        $order = $this->createOrder('mondu', true);

        $this->createAsyncOrderObserver()->execute($this->observerFor($order));

        $this->assertSame([], $this->requestedTypes);
    }

    public function testNewAdminOrderGoesThroughCreateAsyncOnly(): void
    {
        $order = $this->createOrder('mondu', false);

        $this->createOrderObserver()->execute($this->observerFor($order));
        $this->assertSame([], $this->requestedTypes, 'CreateOrder has no checkout to confirm');
        $this->assertSame([], $this->orderHelperCalls);

        $this->createAsyncOrderObserver()->execute($this->observerFor($order));
        $this->assertSame([RequestFactory::CREATE_ASYNC_ORDER], $this->requestedTypes);
    }

    public function testNewAdminOrderKeepsItsStateAfterPlacement(): void
    {
        $order = $this->createOrder('mondu', false);
        $order->setState(Order::STATE_NEW)->setStatus('pending');

        $logHelper = $this->createMock(MonduLogHelper::class);
        $logHelper->method('getTransactionByOrderUid')->willReturn(['mondu_state' => 'confirmed']);

        $observer = new AfterPlaceOrder(
            $this->createMock(ContextHelper::class),
            $this->createMock(MonduFileLogger::class),
            ObjectManager::getInstance()->get(PaymentMethodHelper::class),
            $logHelper,
            $this->createMock(OrderRepositoryInterface::class),
            $this->adminOrder()
        );
        $observer->execute($this->observerFor($order));

        $this->assertSame(Order::STATE_NEW, $order->getState(), 'the buyer has not confirmed it yet');
    }

    /**
     * @return CreateOrder
     */
    private function createOrderObserver(): CreateOrder
    {
        $orderHelper = $this->createMock(OrderHelper::class);
        $orderHelper->method('handleOrderAdjustment')->willReturnCallback(
            function () {
                $this->orderHelperCalls[] = 'handleOrderAdjustment';
            }
        );
        $orderHelper->method('handlePaymentMethodChange')->willReturnCallback(
            function () {
                $this->orderHelperCalls[] = 'handlePaymentMethodChange';
            }
        );

        $logHelper = $this->createMock(MonduLogHelper::class);
        $logHelper->method('updateLogMonduData')->willReturn(1);

        return new CreateOrder(
            $this->createMock(ContextHelper::class),
            $this->createMock(MonduFileLogger::class),
            ObjectManager::getInstance()->get(PaymentMethodHelper::class),
            $this->createMock(CheckoutSession::class),
            $logHelper,
            $this->createMock(MonduTransactionItem::class),
            $orderHelper,
            $this->createRequestFactory(),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(OrderUuidContext::class),
            $this->adminOrder()
        );
    }

    /**
     * @return CreateAsyncOrder
     */
    private function createAsyncOrderObserver(): CreateAsyncOrder
    {
        $backendOrders = $this->createMock(BackendOrders::class);
        $backendOrders->method('isActive')->willReturn(true);

        return new CreateAsyncOrder(
            $this->adminOrder(),
            $backendOrders,
            $this->createMock(ContextHelper::class),
            $this->createMock(CustomerRepositoryInterface::class),
            $this->createMock(MonduFileLogger::class),
            $this->createMock(MonduLogHelper::class),
            $this->createMock(OrderRepositoryInterface::class),
            ObjectManager::getInstance()->get(PaymentMethodHelper::class),
            $this->createRequestFactory()
        );
    }

    /**
     * AdminOrder as it behaves in the admin area.
     *
     * @return AdminOrder
     */
    private function adminOrder(): AdminOrder
    {
        $appState = $this->createMock(AppState::class);
        $appState->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);

        return new AdminOrder($appState);
    }

    /**
     * @return RequestFactory
     */
    private function createRequestFactory(): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('create')->willReturnCallback(
            function (string $type) {
                $this->requestedTypes[] = $type;

                if ($type === RequestFactory::TRANSACTION_CONFIRM_METHOD) {
                    $request = $this->createMock(Confirm::class);
                    $request->method('setValidate')->willReturnSelf();
                } else {
                    $request = $this->createMock(MonduRequestInterface::class);
                }
                $request->method('process')->willReturn([
                    'order' => ['uuid' => self::MONDU_ID, 'state' => 'confirmed'],
                ]);

                return $request;
            }
        );

        return $factory;
    }

    /**
     * @param string $method
     * @param bool $isEdit
     * @return Order
     */
    private function createOrder(string $method, bool $isEdit): Order
    {
        $om = ObjectManager::getInstance();

        /** @var Order $order */
        $order = $om->create(Order::class);
        $order->setData([
            'increment_id' => '000000099-1',
            'store_id' => 1,
            'mondu_reference_id' => self::MONDU_ID,
        ]);
        if ($isEdit) {
            $order->setRelationParentId(98);
            $order->setRelationParentRealId('000000099');
        }

        /** @var Payment $payment */
        $payment = $om->create(Payment::class);
        $payment->setMethod($method);
        $order->setPayment($payment);

        return $order;
    }

    /**
     * @param Order $order
     * @return Observer
     */
    private function observerFor(Order $order): Observer
    {
        $om = ObjectManager::getInstance();
        $event = $om->create(Event::class, ['data' => ['order' => $order]]);

        return $om->create(Observer::class, ['data' => ['event' => $event]]);
    }
}
