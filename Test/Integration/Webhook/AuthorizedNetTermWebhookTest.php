<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Payment\Helper\Data as PaymentHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

/**
 * Integration test: the authorized net term of an async order
 *
 * An async order is logged from the create_async answer, which Mondu sends
 * before deciding and which carries no authorized term. Once order/authorized
 * arrives the term is read from the API, so the merchant sees the term Mondu
 * authorized in the order view, the invoice email and the invoice PDF.
 */
class AuthorizedNetTermWebhookTest extends WebhookTestCase
{
    public function testAuthorizedWebhookStoresTheAuthorizedNetTerm(): void
    {
        $order = $this->createTestOrder('ac.good.' . uniqid() . '@example.com');
        $orderId = (int) $order->getEntityId();

        try {
            $orderUuid = $this->createMonduAsyncOrder($order);

            $this->assertNull(
                $this->logHelper->getTransactionByOrderUid($orderUuid)['authorized_net_term'],
                'create_async answers before Mondu decides, so no term is known yet'
            );

            $authorizedNetTerm = $this->waitForAuthorizedNetTerm($orderUuid, (int) $order->getStoreId());

            $this->webhookController->handleAuthorized(
                $this->webhookParams('order/authorized', $orderUuid, $order->getIncrementId()),
                $order
            );

            $this->assertSame(
                $authorizedNetTerm,
                (int) $this->logHelper->getTransactionByOrderUid($orderUuid)['authorized_net_term'],
                'order/authorized must store the term Mondu authorized'
            );

            $info = $this->om->get(PaymentHelper::class)
                ->getInfoBlock($this->reloadOrder($orderId)->getPayment());

            $this->assertSame(
                ['Payment term' => $authorizedNetTerm . ' days'],
                $info->getSpecificInformation(),
                'The payment info block must show the authorized term'
            );
        } finally {
            $this->cleanup($orderId, $orderUuid ?? '');
        }
    }

    /**
     * Polls the API until Mondu has decided on the order and returns the term it authorized.
     *
     * @param string $orderUuid
     * @param int $storeId
     * @return int
     */
    private function waitForAuthorizedNetTerm(string $orderUuid, int $storeId): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $data = $this->om->get(RequestFactory::class)
                ->create(RequestFactory::TRANSACTION_CONFIRM_METHOD, $storeId)
                ->setValidate(false)
                ->process(['orderUid' => $orderUuid]);

            if (!empty($data['order']['authorized_net_term'])) {
                return (int) $data['order']['authorized_net_term'];
            }

            sleep(2);
        }

        $this->fail('Mondu did not authorize the order in time: ' . json_encode($data['order'] ?? $data));
    }
}
