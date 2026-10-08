<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Log;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Model\Request\Confirm;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: MonduLogHelper::fillMissingAuthorizedNetTerm()
 *
 * Async orders are logged before Mondu decides, so the authorized term is read
 * from GET /orders/{uuid} once a webhook says the order has been decided. The
 * API answer is stubbed; the log row is real.
 */
class FillAuthorizedNetTermTest extends TestCase
{
    private ResourceConnection $resource;

    /** UUIDs inserted during the test, for cleanup. */
    private array $testUuids = [];

    protected function setUp(): void
    {
        $this->resource = ObjectManager::getInstance()->get(ResourceConnection::class);
    }

    protected function tearDown(): void
    {
        if ($this->testUuids) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName('mondu_transactions'),
                ['reference_id IN (?)' => $this->testUuids]
            );
            $this->testUuids = [];
        }
    }

    public function testMissingTermIsReadFromTheApi(): void
    {
        $uuid = $this->insertTransaction(null);
        $calls = 0;

        $this->logHelper(['state' => 'authorized', 'authorized_net_term' => 60], $calls)
            ->fillMissingAuthorizedNetTerm($uuid, 1);

        $this->assertSame(1, $calls);
        $this->assertSame(60, (int) $this->readRow($uuid)['authorized_net_term']);
    }

    public function testStateStoredByTheWebhookIsNotOverwritten(): void
    {
        // order/confirmed has just stored "confirmed"; the API still answers the
        // older "authorized". Taking that over would block shipping the order.
        $uuid = $this->insertTransaction(null, 'confirmed');
        $calls = 0;

        $this->logHelper(['state' => 'authorized', 'authorized_net_term' => 60], $calls)
            ->fillMissingAuthorizedNetTerm($uuid, 1);

        $this->assertSame(60, (int) $this->readRow($uuid)['authorized_net_term']);
        $this->assertSame('confirmed', $this->readRow($uuid)['mondu_state']);
        $this->assertSame(1, (int) $this->readRow($uuid)['is_confirmed']);
    }

    public function testInstalmentsAreNotAskedForATerm(): void
    {
        $uuid = $this->insertTransaction(null, 'authorized', 'monduinstallment');
        $calls = 0;

        $this->logHelper(['state' => 'authorized', 'authorized_net_term' => 60], $calls)
            ->fillMissingAuthorizedNetTerm($uuid, 1);

        $this->assertSame(0, $calls, 'Instalments are never settled on a term');
        $this->assertNull($this->readRow($uuid)['authorized_net_term']);
    }

    public function testKnownTermIsNotReadAgain(): void
    {
        $uuid = $this->insertTransaction(30);
        $calls = 0;

        $this->logHelper(['state' => 'confirmed', 'authorized_net_term' => 60], $calls)
            ->fillMissingAuthorizedNetTerm($uuid, 1);

        $this->assertSame(0, $calls, 'A stored term needs no API call');
        $this->assertSame(30, (int) $this->readRow($uuid)['authorized_net_term']);
    }

    public function testApiFailureDoesNotEscape(): void
    {
        $uuid = $this->insertTransaction(null);
        $calls = 0;

        // Mondu answers an error body without an order, as for an unknown uuid.
        $this->logHelper(null, $calls)->fillMissingAuthorizedNetTerm($uuid, 1);

        $this->assertSame(1, $calls);
        $this->assertNull($this->readRow($uuid)['authorized_net_term']);
    }

    /**
     * Log helper whose GET /orders/{uuid} answers the given order.
     *
     * @param array|null $order
     * @param int $calls
     * @return MonduLogHelper
     */
    private function logHelper(?array $order, int &$calls): MonduLogHelper
    {
        $request = $this->createStub(Confirm::class);
        $request->method('setValidate')->willReturnSelf();
        $request->method('process')->willReturnCallback(
            function () use ($order, &$calls): array {
                $calls++;

                return $order === null
                    ? ['errors' => [['details' => 'Generic error']], 'status' => 404]
                    : ['order' => $order];
            }
        );

        $factory = $this->createStub(RequestFactory::class);
        $factory->method('create')->willReturnCallback(
            function (string $method) use ($request) {
                $this->assertSame(RequestFactory::TRANSACTION_CONFIRM_METHOD, $method);

                return $request;
            }
        );

        return ObjectManager::getInstance()->create(MonduLogHelper::class, ['requestFactory' => $factory]);
    }

    private function insertTransaction(
        ?int $authorizedNetTerm,
        string $monduState = 'processing',
        string $paymentMethod = 'mondu'
    ): string {
        $uuid = 'test-fill-net-term-' . uniqid();
        $this->testUuids[] = $uuid;

        $this->resource->getConnection()->insert(
            $this->resource->getTableName('mondu_transactions'),
            [
                'reference_id'        => $uuid,
                'order_id'            => 0,
                'store_id'            => 1,
                'mondu_state'         => $monduState,
                'mode'                => 'sandbox',
                'payment_method'      => $paymentMethod,
                'is_confirmed'        => $monduState === 'confirmed' ? 1 : 0,
                'authorized_net_term' => $authorizedNetTerm,
                'order_flow'          => 'async',
                'created_at'          => date('Y-m-d H:i:s'),
            ]
        );

        return $uuid;
    }

    private function readRow(string $uuid): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('mondu_transactions'))
                ->where('reference_id = ?', $uuid)
        );
    }
}
