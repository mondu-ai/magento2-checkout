<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Sales\Model\Order;

/**
 * Integration test: order/authorized only moves an async order that waits for the buyer.
 *
 * Mondu sends the topic for checkout orders as well, and it can arrive after
 * order/confirmed. Neither case may put the order back into payment review.
 * The Mondu orders are recorded locally only, no request reaches Mondu that matters
 * for the outcome.
 */
class AuthorizedWebhookScopeTest extends WebhookTestCase
{
    public function testCheckoutOrderKeepsItsState(): void
    {
        $this->assertAuthorizedLeavesOrderAlone('authorization');
    }

    public function testAsyncOrderConfirmedBeforeAuthorizedKeepsItsState(): void
    {
        $this->assertAuthorizedLeavesOrderAlone('async');
    }

    private function assertAuthorizedLeavesOrderAlone(string $orderFlow): void
    {
        $order = $this->createTestOrder('ac.good.' . uniqid() . '@example.com');
        $orderId = (int) $order->getEntityId();
        $orderUuid = 'test-' . uniqid();

        try {
            $order->setData('mondu_reference_id', $orderUuid);
            $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
            $this->orderRepository->save($order);
            $this->logHelper->logTransaction($order, ['state' => 'confirmed'], null, 'mondu', $orderFlow);

            [$body, $status] = $this->webhookController->handleAuthorized(
                $this->webhookParams('order/authorized', $orderUuid, $order->getIncrementId()),
                $order
            );

            $this->assertSame(200, $status);
            $this->assertSame('ok', $body['message']);

            $fresh = $this->reloadOrder($orderId);
            $this->assertSame(Order::STATE_PROCESSING, $fresh->getState());
            $this->assertSame(Order::STATE_PROCESSING, $fresh->getStatus());
            $this->assertSame(
                'confirmed',
                $this->logHelper->getTransactionByOrderUid($orderUuid)['mondu_state'],
                'mondu_transactions.mondu_state must not go back to "authorized"'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid);
        }
    }
}
