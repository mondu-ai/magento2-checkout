<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Sales\Model\Order;

/**
 * Integration test: order/pending webhook
 *
 * Mondu sandbox routing: buyer email starts with "pending.good."
 * Expected result: Magento order state AND status both become "payment_review".
 */
class PendingWebhookTest extends WebhookTestCase
{
    public function testPendingWebhookSetsPaymentReview(): void
    {
        $email = 'pending.good.' . uniqid() . '@example.com';

        $order = $this->createTestOrder($email);
        $orderId = (int) $order->getEntityId();

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            $params = $this->webhookParams(
                'order/pending',
                $orderUuid,
                $order->getIncrementId()
            );

            [$body, $status] = $this->webhookController->handlePending($params, $order);

            $this->assertSame(200, $status, 'Expected HTTP 200 from handlePending');
            $this->assertSame('ok', $body['message'], 'Expected message=ok from handlePending');

            $fresh = $this->reloadOrder($orderId);

            $this->assertSame(
                Order::STATE_PAYMENT_REVIEW,
                $fresh->getState(),
                'Order state must be payment_review after order/pending'
            );
            $this->assertSame(
                Order::STATE_PAYMENT_REVIEW,
                $fresh->getStatus(),
                'Order status must be payment_review after order/pending'
            );

            $transaction = $this->logHelper->getTransactionByOrderUid($orderUuid);
            $this->assertSame(
                'pending',
                $transaction['mondu_state'],
                'mondu_transactions.mondu_state must be "pending"'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }
}