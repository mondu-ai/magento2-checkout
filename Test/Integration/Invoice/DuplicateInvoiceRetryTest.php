<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Invoice;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\MonduTransactionItem;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Request\RequestInterface as MonduRequestInterface;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Integration test: a shipment retried after Mondu created the invoice but its
 * answer got lost.
 *
 * The retry is refused with "external_reference_id must be unique". When the
 * Mondu order already holds an invoice with that number, it is the result of the
 * earlier attempt: the mapping is written and the shipment goes through. Only a
 * number Mondu holds on another order still stops the shipment.
 */
#[AllowMockObjectsWithoutExpectations]
class DuplicateInvoiceRetryTest extends TestCase
{
    private const MONDU_ID = 'b5f1f5a6-0d6b-4bd5-8c3c-6f2f8b0b6a11';
    private const INVOICE_UUID = '6f3a2b1c-6e0d-4c55-9a2e-1b7a3f6d9e04';

    private const DUPLICATE = [
        'errors' => [['name' => 'external_reference_id', 'details' => 'must be unique']],
    ];

    /**
     * Mondu request types asked for, in order.
     *
     * @var string[]
     */
    private array $requestedTypes = [];

    /**
     * Invoice mappings written to the Mondu log.
     *
     * @var array
     */
    private array $writtenMappings = [];

    protected function setUp(): void
    {
        $this->requestedTypes = [];
        $this->writtenMappings = [];
    }

    public function testWholeOrderRetryLinksTheInvoiceMonduAlreadyHolds(): void
    {
        $helper = $this->createHelper([
            ['invoice_number' => '000000042', 'uuid' => self::INVOICE_UUID, 'state' => 'created'],
        ]);

        $data = $helper->createInvoiceForWholeOrder($this->createOrder());

        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame(self::INVOICE_UUID, $data['invoice']['uuid']);
        $this->assertSame([RequestFactory::SHIP_ORDER, RequestFactory::ORDER_INVOICES], $this->requestedTypes);
        $this->assertSame(self::INVOICE_UUID, $this->writtenMappings[0]['000000042']['uuid']);
    }

    public function testWholeOrderShipmentStillFailsWhenTheNumberBelongsToAnotherOrder(): void
    {
        $helper = $this->createHelper([
            ['invoice_number' => '000000041', 'uuid' => self::INVOICE_UUID, 'state' => 'created'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already registered at Mondu for another order');

        $helper->handleInvoiceOrder($this->createOrder(), $this->createShipment(), $this->createLog());
    }

    public function testInvoiceRetryLinksTheInvoiceAndShips(): void
    {
        $helper = $this->createHelper([
            ['invoice_number' => '000000007', 'uuid' => self::INVOICE_UUID, 'state' => 'created'],
        ]);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getId')->willReturn(7);
        $invoice->method('getEntityId')->willReturn(7);
        $invoice->method('getIncrementId')->willReturn('000000007');
        $invoice->method('getBaseGrandTotal')->willReturn(119.0);
        $invoice->method('getOrder')->willReturn($this->createOrder());

        $comments = [];
        $shipment = $this->createShipment();
        $shipment->method('addComment')->willReturnCallback(
            function ($comment) use (&$comments, $shipment) {
                $comments[] = (string) $comment;
                return $shipment;
            }
        );

        $mapping = [];
        $method = new ReflectionMethod(InvoiceOrderHelper::class, 'createInvoiceForItem');
        $method->invokeArgs($helper, [self::MONDU_ID, $invoice, $shipment, &$mapping, []]);

        $this->assertSame(self::INVOICE_UUID, $mapping['000000007']['uuid']);
        $this->assertSame(7, $mapping['000000007']['local_id']);
        $this->assertSame(['Mondu: invoice created with id ' . self::INVOICE_UUID], $comments);
    }

    public function testInvoiceRetryFailsWhenMonduCannotListTheInvoices(): void
    {
        $helper = $this->createHelper(null);

        $this->expectException(LocalizedException::class);

        $helper->handleInvoiceOrder($this->createOrder(), $this->createShipment(), $this->createLog());
    }

    /**
     * @param array|null $monduInvoices Invoices the Mondu order holds, null when listing them fails
     * @return InvoiceOrderHelper
     */
    private function createHelper(?array $monduInvoices): InvoiceOrderHelper
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('create')->willReturnCallback(
            function (string $type) use ($monduInvoices) {
                $this->requestedTypes[] = $type;
                $request = $this->createMock(MonduRequestInterface::class);

                if ($type === RequestFactory::ORDER_INVOICES && $monduInvoices === null) {
                    $request->method('process')->willThrowException(new LocalizedException(__('timeout')));
                    return $request;
                }

                $request->method('process')->willReturn(
                    $type === RequestFactory::ORDER_INVOICES ? $monduInvoices : self::DUPLICATE
                );

                return $request;
            }
        );

        $logHelper = $this->createMock(MonduLogHelper::class);
        $logHelper->method('updateLogInvoice')->willReturnCallback(
            function ($monduId, $mapping) {
                $this->writtenMappings[] = $mapping;
            }
        );

        $configProvider = $this->createMock(ConfigProvider::class);
        $configProvider->method('isInvoiceRequiredForShipping')->willReturn(false);
        $configProvider->method('getPdfUrl')->willReturn('https://example.test/invoice.pdf');

        $orderHelper = $this->createMock(OrderHelper::class);
        $orderHelper->method('addAmountsToInvoice')->willReturnArgument(1);
        $orderHelper->method('addLineItemsToInvoice')->willReturnArgument(1);

        return new InvoiceOrderHelper(
            $configProvider,
            $this->createMock(ManagerInterface::class),
            $this->createMock(MonduFileLogger::class),
            $logHelper,
            $this->createMock(MonduTransactionItem::class),
            $orderHelper,
            $factory,
            ObjectManager::getInstance()->get(SerializerInterface::class)
        );
    }

    /**
     * @return Order
     */
    private function createOrder(): Order
    {
        $order = ObjectManager::getInstance()->create(Order::class);
        $order->setData([
            'increment_id' => '000000042',
            'mondu_reference_id' => self::MONDU_ID,
            'base_grand_total' => 119.0,
            'store_id' => 1,
        ]);

        return $order;
    }

    /**
     * @return Shipment&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createShipment(): Shipment
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getAllTracks')->willReturn([]);

        return $shipment;
    }

    /**
     * @return \Mondu\Mondu\Model\Log
     */
    private function createLog(): \Mondu\Mondu\Model\Log
    {
        return $this->createMock(\Mondu\Mondu\Model\Log::class);
    }
}
