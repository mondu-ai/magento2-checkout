<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Log;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Model\Payment\AsyncOrderFields as F;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: MonduLogHelper::getNetTermForOrder()
 *
 * The term shown to the merchant for invoicing:
 *  - the term Mondu authorized wins over the one picked
 *  - the picked term stands in until Mondu has decided
 *  - methods not settled on a term have none, whatever is left on the payment
 */
class NetTermForOrderTest extends TestCase
{
    private MonduLogHelper $logHelper;
    private ResourceConnection $resource;

    /** Fake order ids inserted during the test, for cleanup. */
    private array $orderIds = [];

    protected function setUp(): void
    {
        $om = ObjectManager::getInstance();
        $this->logHelper = $om->get(MonduLogHelper::class);
        $this->resource  = $om->get(ResourceConnection::class);
    }

    protected function tearDown(): void
    {
        if ($this->orderIds) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName('mondu_transactions'),
                ['order_id IN (?)' => $this->orderIds]
            );
            $this->orderIds = [];
        }
    }

    public function testAuthorizedTermWinsOverThePickedOne(): void
    {
        $order = $this->buildOrder('mondu', 30, 60);

        $this->assertSame(60, $this->logHelper->getNetTermForOrder($order));
    }

    public function testPickedTermStandsInUntilMonduHasDecided(): void
    {
        $order = $this->buildOrder('mondusepa', 30, null);

        $this->assertSame(30, $this->logHelper->getNetTermForOrder($order));
    }

    public function testNoTermWhenNeitherIsKnown(): void
    {
        $order = $this->buildOrder('mondu', null, null);

        $this->assertNull($this->logHelper->getNetTermForOrder($order));
    }

    public function testInstalmentsHaveNoTermEvenWithOneLeftOnThePayment(): void
    {
        $order = $this->buildOrder('monduinstallment', 30, 30);

        $this->assertNull($this->logHelper->getNetTermForOrder($order));
    }

    /**
     * Builds an unsaved order and, when a term is authorized, its log row.
     *
     * @param string $method
     * @param int|null $pickedNetTerm
     * @param int|null $authorizedNetTerm
     * @return Order
     */
    private function buildOrder(string $method, ?int $pickedNetTerm, ?int $authorizedNetTerm): Order
    {
        $orderId = random_int(900000000, 999999999);
        $this->orderIds[] = $orderId;

        $om = ObjectManager::getInstance();
        $payment = $om->create(Payment::class);
        $payment->setMethod($method);
        if ($pickedNetTerm !== null) {
            $payment->setAdditionalInformation(F::FIELD_NET_TERM, (string) $pickedNetTerm);
        }

        $order = $om->create(Order::class);
        $order->setEntityId($orderId);
        $order->setPayment($payment);

        $this->resource->getConnection()->insert(
            $this->resource->getTableName('mondu_transactions'),
            [
                'reference_id'        => 'test-net-term-' . uniqid(),
                'order_id'            => $orderId,
                'store_id'            => 1,
                'mondu_state'         => $authorizedNetTerm ? 'authorized' : 'processing',
                'mode'                => 'sandbox',
                'payment_method'      => $method,
                'authorized_net_term' => $authorizedNetTerm,
                'order_flow'          => 'async',
                'created_at'          => date('Y-m-d H:i:s'),
            ]
        );

        return $order;
    }
}
