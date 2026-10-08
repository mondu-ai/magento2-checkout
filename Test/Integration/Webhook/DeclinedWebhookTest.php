<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Sales\Model\Order;

/**
 * Integration test: order/declined webhook
 *
 * Mondu sandbox routing: buyer email "declined@example.com"
 * Expected result: order status = "canceled",
 *                  mondu_transactions.mondu_state = "declined".
 *
 * Note: handleDeclinedOrCanceled only changes the *status*, not the *state*.
 * The order state stays at its pre-decline value (new / payment_review).
 */
class DeclinedWebhookTest extends WebhookTestCase
{
    public function testDeclinedWebhookCancelsOrder(): void
    {
        // "declined@example.com" is a fixed Mondu sandbox email that always
        // triggers an order/declined webhook.
        $email = 'declined@example.com';

        // Start from STATE_PROCESSING so Magento's cancel() state machine allows it.
        $order = $this->createTestOrder($email);
        $orderId = (int) $order->getEntityId();

        // Manually put the order in a state that allows cancellation.
        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);
        $this->orderRepository->save($order);
        $order = $this->reloadOrder($orderId);

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            $params = $this->webhookParams(
                'order/declined',
                $orderUuid,
                $order->getIncrementId(),
                ['reason' => 'credit_limit_exceeded']
            );

            [$body, $status] = $this->webhookController->handleDeclinedOrCanceled($params, $order);

            $this->assertSame(200, $status, 'Expected HTTP 200 from handleDeclinedOrCanceled');
            $this->assertSame('ok', $body['message'], 'Expected message=ok from handleDeclinedOrCanceled');

            $fresh = $this->reloadOrder($orderId);

            $this->assertSame(
                Order::STATE_CANCELED,
                $fresh->getStatus(),
                'Order status must be "canceled" after order/declined'
            );

            $transaction = $this->logHelper->getTransactionByOrderUid($orderUuid);
            $this->assertSame(
                'declined',
                $transaction['mondu_state'],
                'mondu_transactions.mondu_state must be "declined"'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }

    public function testFraudDeclinedSetsFraudStatus(): void
    {
        $email = 'declined@example.com';

        $order = $this->createTestOrder($email, 'Fraud Corp GmbH');
        $orderId = (int) $order->getEntityId();

        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);
        $this->orderRepository->save($order);
        $order = $this->reloadOrder($orderId);

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            $params = $this->webhookParams(
                'order/declined',
                $orderUuid,
                $order->getIncrementId(),
                ['reason' => 'buyer_fraud']
            );

            [$body, $status] = $this->webhookController->handleDeclinedOrCanceled($params, $order);
            $this->assertSame(200, $status);

            $fresh = $this->reloadOrder($orderId);

            $this->assertSame(
                Order::STATUS_FRAUD,
                $fresh->getStatus(),
                'Order status must be "fraud" when decline reason is buyer_fraud'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }
}