<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface as HttpRequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Mondu\Mondu\Helpers\BulkActions;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Request\RequestInterface as MonduRequestInterface;
use Mondu\Mondu\Observer\UpdateOrder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: which Mondu call a refund produces.
 *
 * UpdateOrder has to choose between a credit note and a cancellation. The Mondu
 * log helper is the real one, backed by rows inserted into mondu_transactions;
 * everything that would talk to Mondu is a mock that only records which request
 * type was asked for.
 *
 * The regression this covers: an order Mondu had already invoiced was cancelled
 * on a full refund because the cached mondu_state had not caught up with the
 * shipment, even though the invoice mapping was stored in the same log row.
 */
#[AllowMockObjectsWithoutExpectations]
class UpdateOrderRefundRoutingTest extends TestCase
{
    private const INVOICE_UUID = 'eca717f8-79c2-50b8-8208-5de6b4675343';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var MonduLogHelper
     */
    private MonduLogHelper $logHelper;

    /**
     * Mondu request types the observer asked the factory for, in order.
     *
     * @var array
     */
    private array $requestedTypes = [];

    /**
     * Error messages pushed to the admin.
     *
     * @var array
     */
    private array $errorMessages = [];

    /**
     * UUIDs inserted during the test, for cleanup.
     *
     * @var array
     */
    private array $testUuids = [];

    protected function setUp(): void
    {
        $om = ObjectManager::getInstance();
        $this->resource = $om->get(ResourceConnection::class);
        $this->serializer = $om->get(SerializerInterface::class);
        $this->logHelper = $om->get(MonduLogHelper::class);
        $this->requestedTypes = [];
        $this->errorMessages = [];
    }

    protected function tearDown(): void
    {
        if ($this->testUuids) {
            $connection = $this->resource->getConnection();
            $connection->delete(
                $this->resource->getTableName('mondu_transactions'),
                ['reference_id IN (?)' => $this->testUuids]
            );
            $this->testUuids = [];
        }
    }

    // -----------------------------------------------------------------------
    // Full refund
    // -----------------------------------------------------------------------

    /**
     * The regression. Mondu invoiced the order, but the state refresh that runs
     * right after the invoice never landed, so the log still says 'confirmed'.
     * The refund must still go out as a credit note.
     */
    public function testFullRefundSendsCreditNoteWhenStateIsStale(): void
    {
        $uuid = $this->insertTransaction('confirmed', $this->invoiceMapping());

        $this->runObserver($uuid, ['creditmemo' => ['creditmemo_mondu_id' => self::INVOICE_UUID]]);

        $this->assertSame([RequestFactory::MEMO], $this->requestedTypes);
        $this->assertSame([], $this->errorMessages);
    }

    /**
     * Same order, this time with the state the module was supposed to have.
     * Behaviour must not change.
     */
    public function testFullRefundSendsCreditNoteWhenStateIsShipped(): void
    {
        $uuid = $this->insertTransaction('shipped', $this->invoiceMapping());

        $this->runObserver($uuid, ['creditmemo' => ['creditmemo_mondu_id' => self::INVOICE_UUID]]);

        $this->assertSame([RequestFactory::MEMO], $this->requestedTypes);
    }

    /**
     * Nothing was ever invoiced at Mondu, so there is nothing to write a credit
     * note against. Cancelling is the correct outcome here.
     */
    public function testFullRefundCancelsWhenNothingWasInvoiced(): void
    {
        $uuid = $this->insertTransaction('confirmed', null);

        $this->runObserver($uuid, []);

        $this->assertSame([RequestFactory::CANCEL], $this->requestedTypes);
    }

    /**
     * Every invoice Mondu had was cancelled again, so the recorded mapping is no
     * longer a reason to prefer a credit note.
     */
    public function testFullRefundCancelsWhenEveryInvoiceWasCanceled(): void
    {
        $uuid = $this->insertTransaction('confirmed', [
            '000000004' => [
                'uuid' => self::INVOICE_UUID,
                'state' => 'canceled',
                'local_id' => '4',
            ],
        ]);

        $this->runObserver($uuid, []);

        $this->assertSame([RequestFactory::CANCEL], $this->requestedTypes);
    }

    /**
     * A cancellation writes the state Mondu reports back to the log.
     */
    public function testCancellationStoresTheStateReturnedByMondu(): void
    {
        $uuid = $this->insertTransaction('confirmed', null);

        $this->runObserver($uuid, []);

        $log = $this->logHelper->getTransactionByOrderUid($uuid);
        $this->assertSame('canceled', $log['mondu_state']);
    }

    // -----------------------------------------------------------------------
    // Refund without the invoice picker
    // -----------------------------------------------------------------------

    /**
     * No invoice was selected, which the admin form normally guarantees. With an
     * invoice on record the refund must not be turned into a cancellation, and
     * must not be rejected as a refund before shipment either.
     */
    public function testMissingInvoiceIdWithInvoiceOnRecordDoesNotCancel(): void
    {
        $uuid = $this->insertTransaction('confirmed', $this->invoiceMapping());

        $this->runObserver($uuid, ['creditmemo' => []]);

        $this->assertSame([], $this->requestedTypes);
        $this->assertStringNotContainsString(
            'You cant partially refund order before shipment',
            implode("\n", $this->errorMessages)
        );
    }

    /**
     * Without an invoice, a refund on an order that was never shipped is still
     * refused. canCreditmemo() is true here, so this is a partial refund.
     */
    public function testMissingInvoiceIdBeforeShipmentIsRefused(): void
    {
        $uuid = $this->insertTransaction('confirmed', null);

        $this->runObserver($uuid, ['creditmemo' => []], true);

        $this->assertSame([], $this->requestedTypes);
        $this->assertStringContainsString(
            'You cant partially refund order before shipment',
            implode("\n", $this->errorMessages)
        );
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    /**
     * Runs the observer against a credit memo for the given Mondu order.
     *
     * @param string $monduId
     * @param array $requestParams
     * @param bool $canCreditmemo whether Magento still has something left to refund
     * @return void
     */
    private function runObserver(string $monduId, array $requestParams, bool $canCreditmemo = false): void
    {
        $om = ObjectManager::getInstance();

        // getMonduReferenceId is a magic getter, so DataObject::__call has to stay
        // intact and resolve it through the stubbed getData().
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId',
                'getEntityId',
                'getIncrementId',
                'getStoreId',
                'getState',
                'getStatus',
                'getData',
                'canCreditmemo',
                'canInvoice',
                'addCommentToStatusHistory',
            ])
            ->getMock();
        $order->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $key === 'mondu_reference_id' ? $monduId : null
        );
        $order->method('getId')->willReturn(4711);
        $order->method('getEntityId')->willReturn(4711);
        $order->method('getIncrementId')->willReturn('000000016');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn('processing');
        $order->method('getStatus')->willReturn('processing');
        $order->method('canCreditmemo')->willReturn($canCreditmemo);
        $order->method('canInvoice')->willReturn(false);

        $creditMemo = $this->createMock(Creditmemo::class);
        $creditMemo->method('getOrder')->willReturn($order);
        $creditMemo->method('getEntityId')->willReturn(1);
        $creditMemo->method('getIncrementId')->willReturn('000000001');
        $creditMemo->method('getBaseGrandTotal')->willReturn(54.00);

        $httpRequest = $this->createMock(HttpRequestInterface::class);
        $httpRequest->method('getParams')->willReturn($requestParams);

        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->method('addErrorMessage')->willReturnCallback(
            function ($message) use ($messageManager) {
                $this->errorMessages[] = (string) $message;
                return $messageManager;
            }
        );

        $observerInstance = new UpdateOrder(
            $this->createMock(ContextHelper::class),
            $this->createMock(MonduFileLogger::class),
            $this->createMock(PaymentMethodHelper::class),
            $this->createMock(BulkActions::class),
            $messageManager,
            $this->logHelper,
            $this->createRequestFactoryMock(),
            $httpRequest,
            $this->createMock(OrderRepositoryInterface::class)
        );

        $event = $om->create(Event::class, ['data' => ['creditmemo' => $creditMemo]]);
        $observerInstance->_execute($om->create(Observer::class, ['data' => ['event' => $event]]));
    }

    /**
     * Request factory that records the requested type and answers with a
     * successful Mondu response for it.
     *
     * @return RequestFactory
     */
    private function createRequestFactoryMock(): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('create')->willReturnCallback(
            function (string $method) {
                $this->requestedTypes[] = $method;

                $response = match ($method) {
                    RequestFactory::CANCEL => ['order' => ['state' => 'canceled']],
                    default => ['credit_note' => ['uuid' => 'a0d1cbb4-4d3e-4a6d-9a4e-0c5c0d0e1f22']],
                };

                $request = $this->createMock(MonduRequestInterface::class);
                $request->method('process')->willReturn($response);

                return $request;
            }
        );

        return $factory;
    }

    /**
     * One live Mondu invoice, exactly as the module records it after shipment.
     *
     * @return array
     */
    private function invoiceMapping(): array
    {
        return [
            '000000004' => [
                'uuid' => self::INVOICE_UUID,
                'state' => 'created',
                'local_id' => '4',
            ],
        ];
    }

    /**
     * Inserts a minimal mondu_transactions row and returns its UUID.
     *
     * @param string $monduState
     * @param array|null $addons
     * @return string
     */
    private function insertTransaction(string $monduState, ?array $addons): string
    {
        $uuid = 'test-refund-' . uniqid();
        $this->testUuids[] = $uuid;

        $connection = $this->resource->getConnection();
        $connection->insert(
            $this->resource->getTableName('mondu_transactions'),
            [
                'reference_id' => $uuid,
                'order_id' => 0,
                'store_id' => 1,
                'mondu_state' => $monduState,
                'mode' => 'sandbox',
                'payment_method' => 'mondu',
                'is_confirmed' => 1,
                'order_flow' => 'async',
                'addons' => $this->serializer->serialize($addons),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $uuid;
    }
}
