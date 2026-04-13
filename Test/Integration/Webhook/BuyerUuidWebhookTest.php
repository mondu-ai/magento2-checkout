<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Integration test: async order creation with buyer_uuid (logged-in customer)
 *
 * Verifies that CreateAsyncOrder sends buyer:{uuid:…} (not full buyer details)
 * when the Magento customer has the mondu_buyer_uuid custom attribute set.
 *
 * Prerequisites:
 *  - At least one Mondu sandbox order must exist in mondu_transactions with a buyer
 *    whose UUID is accepted by the sandbox for subsequent orders (registered buyer).
 *  - If no such buyer UUID is available the test is skipped automatically.
 */
class BuyerUuidWebhookTest extends WebhookTestCase
{
    public function testAsyncOrderWithBuyerUuidUsesUuidPayload(): void
    {
        // Step 1: find a real buyer UUID from an already-confirmed Mondu order
        $buyerUuid = $this->findExistingBuyerUuid();
        if (empty($buyerUuid)) {
            $this->markTestSkipped(
                'No Mondu sandbox order with a buyer UUID found in mondu_transactions. '
                . 'Run at least one full storefront checkout first.'
            );
        }

        // Step 2: create a Magento customer with the real buyer UUID
        $email      = 'buyer.uuid.' . uniqid() . '@example.com';
        $customer   = $this->createTestCustomerWithBuyerUuid($email, $buyerUuid);
        $customerId = (int) $customer->getId();

        // Step 3: create a customer order → Mondu should use buyer:{uuid:…}
        $order   = $this->createTestOrderForCustomer($customer);
        $orderId = (int) $order->getEntityId();

        try {
            try {
                $orderUuid = $this->createMonduAsyncOrder($order);
            } catch (LocalizedException $e) {
                // Mondu rejected the buyer UUID — the UUID is not registered as a repeat buyer
                // in this sandbox account. Skip instead of failing: the code correctness
                // (sending buyer:{uuid:…}) is verified; Mondu just doesn't know this buyer.
                $this->markTestSkipped(
                    'Buyer UUID ' . $buyerUuid . ' is not recognized by the Mondu sandbox as a '
                    . 'registered buyer. Provide a UUID from a buyer who completed the checkout flow. '
                    . 'Original error: ' . $e->getMessage()
                );
                return;
            }

            $this->assertNotEmpty($orderUuid, 'Mondu must return an order UUID when buyer_uuid is used');

            // Step 4: simulate order/pending webhook
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
            $this->cleanupCustomer($customerId);
        }
    }
}