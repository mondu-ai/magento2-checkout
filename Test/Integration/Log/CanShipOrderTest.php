<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Log;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: MonduLogHelper::canShipOrder()
 *
 * Tests the shipment-guard logic against the mondu_transactions table directly.
 * No Mondu API calls are made — rows are inserted and cleaned up manually.
 *
 * canShipOrder() must return:
 *  true  — confirmed, partially_shipped, partially_complete
 *  false — pending, authorized, declined, shipped, complete (and unknown)
 */
class CanShipOrderTest extends TestCase
{
    private MonduLogHelper $logHelper;
    private ResourceConnection $resource;

    /** UUIDs inserted during the test, for cleanup. */
    private array $testUuids = [];

    protected function setUp(): void
    {
        $om = ObjectManager::getInstance();
        $this->logHelper = $om->get(MonduLogHelper::class);
        $this->resource  = $om->get(ResourceConnection::class);
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
    // States that should allow shipping
    // -----------------------------------------------------------------------

    public function testConfirmedCanShip(): void
    {
        $uuid = $this->insertTransaction('confirmed');
        $this->assertTrue($this->logHelper->canShipOrder($uuid));
    }

    public function testPartiallyShippedCanShip(): void
    {
        $uuid = $this->insertTransaction('partially_shipped');
        $this->assertTrue($this->logHelper->canShipOrder($uuid));
    }

    public function testPartiallyCompleteCanShip(): void
    {
        $uuid = $this->insertTransaction('partially_complete');
        $this->assertTrue($this->logHelper->canShipOrder($uuid));
    }

    // -----------------------------------------------------------------------
    // States that must NOT allow shipping
    // -----------------------------------------------------------------------

    public function testPendingCannotShip(): void
    {
        $uuid = $this->insertTransaction('pending');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testAuthorizedCannotShip(): void
    {
        $uuid = $this->insertTransaction('authorized');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testDeclinedCannotShip(): void
    {
        $uuid = $this->insertTransaction('declined');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testShippedCannotShip(): void
    {
        // shipped means fully shipped — no more shipping allowed
        $uuid = $this->insertTransaction('shipped');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testCompleteCannotShip(): void
    {
        $uuid = $this->insertTransaction('complete');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testUnknownStateCannotShip(): void
    {
        $uuid = $this->insertTransaction('some_unknown_state');
        $this->assertFalse($this->logHelper->canShipOrder($uuid));
    }

    public function testMissingTransactionCannotShip(): void
    {
        $this->assertFalse($this->logHelper->canShipOrder('nonexistent-uuid-' . uniqid()));
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Inserts a minimal mondu_transactions row and returns its UUID.
     */
    private function insertTransaction(string $monduState): string
    {
        $uuid = 'test-' . $monduState . '-' . uniqid();
        $this->testUuids[] = $uuid;

        $connection = $this->resource->getConnection();
        $connection->insert(
            $this->resource->getTableName('mondu_transactions'),
            [
                'reference_id'  => $uuid,
                'order_id'      => 0,
                'store_id'      => 1,
                'mondu_state'   => $monduState,
                'mode'          => 'sandbox',
                'payment_method' => 'mondu',
                'is_confirmed'  => $monduState === 'confirmed' ? 1 : 0,
                'order_flow'    => 'async',
                'created_at'    => date('Y-m-d H:i:s'),
            ]
        );

        return $uuid;
    }
}