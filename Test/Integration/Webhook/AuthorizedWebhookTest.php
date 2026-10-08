<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Sales\Model\Order;
use Mondu\Mondu\Setup\Patch\Data\PendingBuyerConfirmationStatus;

/**
 * Integration test: order/authorized webhook
 *
 * Mondu sandbox routing: buyer email starts with "ac.good."
 * After authorization Mondu sends order/authorized before the buyer confirms.
 *
 * Expected result: order state = payment_review,
 *                  order status = mondu_pending_buyer_confirmation,
 *                  is_confirmed in mondu_transactions remains 0.
 */
class AuthorizedWebhookTest extends WebhookTestCase
{
    public function testAuthorizedWebhookSetsPendingBuyerConfirmation(): void
    {
        $email = 'ac.good.' . uniqid() . '@example.com';

        $order = $this->createTestOrder($email);
        $orderId = (int) $order->getEntityId();

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            $params = $this->webhookParams(
                'order/authorized',
                $orderUuid,
                $order->getIncrementId()
            );

            [$body, $status] = $this->webhookController->handleAuthorized($params, $order);

            $this->assertSame(200, $status, 'Expected HTTP 200 from handleAuthorized');
            $this->assertSame('ok', $body['message'], 'Expected message=ok from handleAuthorized');

            $fresh = $this->reloadOrder($orderId);

            $this->assertSame(
                Order::STATE_PAYMENT_REVIEW,
                $fresh->getState(),
                'Order state must be payment_review after order/authorized'
            );
            $this->assertSame(
                PendingBuyerConfirmationStatus::STATUS_CODE,
                $fresh->getStatus(),
                'Order status must be mondu_pending_buyer_confirmation after order/authorized'
            );

            $transaction = $this->logHelper->getTransactionByOrderUid($orderUuid);
            $this->assertSame(
                'authorized',
                $transaction['mondu_state'],
                'mondu_transactions.mondu_state must be "authorized"'
            );
            $this->assertSame(
                0,
                (int) $transaction['is_confirmed'],
                'is_confirmed must stay 0 after order/authorized (buyer not yet confirmed)'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }
}