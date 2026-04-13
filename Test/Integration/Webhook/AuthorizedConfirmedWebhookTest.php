<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Sales\Model\Order;

/**
 * Integration test: full async flow — order/authorized → order/confirmed
 *
 * Mondu sandbox routing: buyer email starts with "ac.good."
 * After the buyer confirms via email, Mondu sends order/confirmed.
 *
 * Expected result after order/confirmed:
 *  - order state  = processing
 *  - order status = processing
 *  - is_confirmed = 1  in mondu_transactions
 *  - mondu_state  = "confirmed"
 */
class AuthorizedConfirmedWebhookTest extends WebhookTestCase
{
    public function testFullAuthorizedThenConfirmedFlow(): void
    {
        $email = 'ac.good.' . uniqid() . '@example.com';

        $order = $this->createTestOrder($email);
        $orderId = (int) $order->getEntityId();

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            // --- Step 1: order/authorized ---
            $authorizedParams = $this->webhookParams(
                'order/authorized',
                $orderUuid,
                $order->getIncrementId()
            );
            [$body, $status] = $this->webhookController->handleAuthorized($authorizedParams, $order);
            $this->assertSame(200, $status, 'handleAuthorized must return 200');

            $afterAuthorized = $this->reloadOrder($orderId);
            $this->assertSame(
                Order::STATE_PAYMENT_REVIEW,
                $afterAuthorized->getState(),
                'State must be payment_review after authorized'
            );

            // --- Step 2: order/confirmed ---
            $confirmedParams = $this->webhookParams(
                'order/confirmed',
                $orderUuid,
                $order->getIncrementId(),
                [
                    'bank_account' => ['iban' => 'DE89370400440532013000'],
                ]
            );
            [$body, $status] = $this->webhookController->handleConfirmed(
                $confirmedParams,
                $afterAuthorized
            );

            $this->assertSame(200, $status, 'handleConfirmed must return 200');
            $this->assertSame('ok', $body['message'], 'Expected message=ok from handleConfirmed');

            $fresh = $this->reloadOrder($orderId);

            $this->assertSame(
                Order::STATE_PROCESSING,
                $fresh->getState(),
                'Order state must be processing after order/confirmed'
            );
            $this->assertSame(
                Order::STATE_PROCESSING,
                $fresh->getStatus(),
                'Order status must be processing after order/confirmed'
            );

            $transaction = $this->logHelper->getTransactionByOrderUid($orderUuid);
            $this->assertSame(
                'confirmed',
                $transaction['mondu_state'],
                'mondu_transactions.mondu_state must be "confirmed"'
            );
            $this->assertSame(
                1,
                (int) $transaction['is_confirmed'],
                'is_confirmed must be 1 after order/confirmed'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }
}