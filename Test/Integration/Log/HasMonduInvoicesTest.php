<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Log;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: MonduLogHelper::hasMonduInvoices()
 *
 * Tests the invoice-mapping check against the mondu_transactions table directly.
 * No Mondu API calls are made — rows are inserted and cleaned up manually.
 *
 * This is what decides between a credit note and a cancellation on a refund, so
 * it has to be true for every order Mondu already invoiced, regardless of the
 * order state the module happens to have cached.
 */
class HasMonduInvoicesTest extends TestCase
{
    /**
     * @var MonduLogHelper
     */
    private MonduLogHelper $logHelper;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * UUIDs inserted during the test, for cleanup.
     *
     * @var array
     */
    private array $testUuids = [];

    protected function setUp(): void
    {
        $om = ObjectManager::getInstance();
        $this->logHelper = $om->get(MonduLogHelper::class);
        $this->resource = $om->get(ResourceConnection::class);
        $this->serializer = $om->get(SerializerInterface::class);
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
    // Orders Mondu has invoiced
    // -----------------------------------------------------------------------

    /**
     * The regression this method exists for: the invoice was created and stored,
     * but the state refresh right after it never landed, so mondu_state is still
     * 'confirmed'. The refund must still be treated as a credit note case.
     */
    public function testInvoiceStoredWhileStateIsStale(): void
    {
        $uuid = $this->insertTransaction('confirmed', [
            '000000004' => [
                'uuid' => 'eca717f8-79c2-50b8-8208-5de6b4675343',
                'state' => 'created',
                'local_id' => '4',
            ],
        ]);

        $this->assertTrue($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testShippedOrderWithInvoice(): void
    {
        $uuid = $this->insertTransaction('shipped', [
            '000000004' => [
                'uuid' => 'eca717f8-79c2-50b8-8208-5de6b4675343',
                'state' => 'created',
                'local_id' => '4',
            ],
        ]);

        $this->assertTrue($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testOneLiveInvoiceAmongCanceledOnes(): void
    {
        $uuid = $this->insertTransaction('partially_shipped', [
            '000000004' => [
                'uuid' => 'eca717f8-79c2-50b8-8208-5de6b4675343',
                'state' => 'canceled',
                'local_id' => '4',
            ],
            '000000005' => [
                'uuid' => '3f2b1c90-1111-2222-3333-444455556666',
                'state' => 'created',
                'local_id' => '5',
            ],
        ]);

        $this->assertTrue($this->logHelper->hasMonduInvoices($uuid));
    }

    // -----------------------------------------------------------------------
    // Orders without a live invoice — these are the cancellation cases
    // -----------------------------------------------------------------------

    public function testConfirmedOrderWithoutInvoice(): void
    {
        $uuid = $this->insertTransaction('confirmed', null);
        $this->assertFalse($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testEmptyInvoiceMapping(): void
    {
        $uuid = $this->insertTransaction('confirmed', []);
        $this->assertFalse($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testAllInvoicesCanceled(): void
    {
        $uuid = $this->insertTransaction('shipped', [
            '000000004' => [
                'uuid' => 'eca717f8-79c2-50b8-8208-5de6b4675343',
                'state' => 'canceled',
                'local_id' => '4',
            ],
        ]);

        $this->assertFalse($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testMappingEntryWithoutUuid(): void
    {
        $uuid = $this->insertTransaction('shipped', [
            '000000004' => [
                'state' => 'created',
                'local_id' => '4',
            ],
        ]);

        $this->assertFalse($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testCorruptedInvoiceMapping(): void
    {
        $uuid = $this->insertTransaction('shipped', null);

        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('mondu_transactions'),
            ['addons' => 'not json at all'],
            ['reference_id = ?' => $uuid]
        );

        $this->assertFalse($this->logHelper->hasMonduInvoices($uuid));
    }

    public function testMissingTransaction(): void
    {
        $this->assertFalse($this->logHelper->hasMonduInvoices('nonexistent-uuid-' . uniqid()));
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Inserts a minimal mondu_transactions row and returns its UUID.
     *
     * @param string $monduState
     * @param array|null $addons
     * @return string
     */
    private function insertTransaction(string $monduState, ?array $addons): string
    {
        $uuid = 'test-invoices-' . uniqid();
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
