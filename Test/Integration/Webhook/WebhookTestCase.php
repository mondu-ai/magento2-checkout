<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\Webhook;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Controller\Webhooks\Index as WebhookController;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Base class for Mondu async-flow webhook integration tests.
 *
 * Each concrete test class tests one webhook topic in isolation.
 * Every test creates its own Magento order + Mondu async order, invokes the
 * webhook handler directly (no HTTP), asserts the result and cleans up.
 *
 * Prerequisites:
 *  - A running Magento installation with env.php credentials.
 *  - Valid Mondu sandbox API key configured under Stores > Config > Mondu.
 *  - Webhook secret stored in core_config_data for the default website.
 */
abstract class WebhookTestCase extends TestCase
{
    protected ObjectManagerInterface $om;
    protected WebhookController $webhookController;
    protected MonduLogHelper $logHelper;
    protected OrderRepositoryInterface $orderRepository;
    protected SerializerInterface $serializer;
    protected EncryptorInterface $encryptor;
    protected StoreManagerInterface $storeManager;
    protected ResourceConnection $resource;

    /** Webhook secret loaded once per test class. */
    private static ?string $webhookSecret = null;

    /** Whether the sandbox API credentials are usable (checked once). */
    private static bool $apiAvailable = false;
    private static bool $apiChecked   = false;

    // -----------------------------------------------------------------------
    // PHPUnit lifecycle
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->om                = ObjectManager::getInstance();
        $this->webhookController = $this->om->get(WebhookController::class);
        $this->logHelper         = $this->om->get(MonduLogHelper::class);
        $this->orderRepository   = $this->om->get(OrderRepositoryInterface::class);
        $this->serializer        = $this->om->get(SerializerInterface::class);
        $this->encryptor         = $this->om->get(EncryptorInterface::class);
        $this->storeManager      = $this->om->get(StoreManagerInterface::class);
        $this->resource          = $this->om->get(ResourceConnection::class);

        $this->requireApiAvailable();
    }

    // -----------------------------------------------------------------------
    // API availability check
    // -----------------------------------------------------------------------

    /**
     * Skips the test if Mondu sandbox credentials are not properly configured.
     */
    private function requireApiAvailable(): void
    {
        if (!self::$apiChecked) {
            self::$apiChecked = true;
            self::$apiAvailable = $this->checkApiCredentials();
        }

        if (!self::$apiAvailable) {
            $this->markTestSkipped(
                'Mondu sandbox API credentials are not configured or not decryptable. '
                . 'Configure them under Stores > Config > Mondu and re-run.'
            );
        }
    }

    private function checkApiCredentials(): bool
    {
        try {
            /** @var \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig */
            $scopeConfig = $this->om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);

            // Check default scope first (most common setup)
            $encrypted = $scopeConfig->getValue('payment/mondu/mondu_key');
            if (!empty($encrypted)) {
                $decrypted = $this->encryptor->decrypt($encrypted);
                if (!empty($decrypted)) {
                    return true;
                }
            }

            // Check per-website overrides
            foreach ($this->storeManager->getWebsites() as $website) {
                $websiteId = (int) $website->getId();
                if ($websiteId === 0) {
                    continue;
                }
                $encrypted = $scopeConfig->getValue(
                    'payment/mondu/mondu_key',
                    ScopeInterface::SCOPE_WEBSITE,
                    $websiteId
                );
                if (empty($encrypted)) {
                    continue;
                }
                $decrypted = $this->encryptor->decrypt($encrypted);
                if (!empty($decrypted)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Order factory helpers
    // -----------------------------------------------------------------------

    /**
     * Creates a minimal Magento order with the given buyer email.
     *
     * The method constructs the order programmatically via the ORM — no HTTP
     * request, no quote — so it works regardless of store-front availability.
     *
     * @param string $buyerEmail   Mondu sandbox routing email.
     * @param string $companyName  Company name for billing address.
     * @return OrderInterface
     */
    protected function createTestOrder(
        string $buyerEmail,
        string $companyName = 'Test GmbH'
    ): OrderInterface {
        /** @var Order $order */
        $order = $this->om->create(Order::class);

        $order->setIncrementId('TEST-' . uniqid())
            ->setCustomerEmail($buyerEmail)
            ->setCustomerFirstname('Max')
            ->setCustomerLastname('Mustermann')
            ->setCustomerIsGuest(true)
            ->setBaseCurrencyCode('EUR')
            ->setOrderCurrencyCode('EUR')
            ->setStoreId(1)
            ->setState(Order::STATE_NEW)
            ->setStatus(Order::STATE_NEW)
            ->setBaseGrandTotal(119.00)
            ->setGrandTotal(119.00)
            ->setBaseSubtotal(100.00)
            ->setSubtotal(100.00)
            ->setBaseTaxAmount(19.00)
            ->setTaxAmount(19.00)
            ->setBaseShippingAmount(0.00)
            ->setShippingAmount(0.00);

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->om->create(\Magento\Sales\Model\Order\Payment::class);
        $payment->setMethod('mondu');
        // Fields the admin order form collects; required by /orders/create_async for `mondu`.
        $payment->setAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM, 'GmbH');
        $payment->setAdditionalInformation(AsyncOrderFields::FIELD_NET_TERM, 30);
        $order->setPayment($payment);

        /** @var Address $billing */
        $billing = $this->om->create(Address::class);
        $billing->setAddressType(Address::TYPE_BILLING)
            ->setFirstname('Max')
            ->setLastname('Mustermann')
            ->setCompany($companyName)
            ->setStreet(['Musterstraße 1'])
            ->setCity('Berlin')
            ->setPostcode('10115')
            ->setCountryId('DE')
            ->setTelephone('+49300000000')
            ->setEmail($buyerEmail);

        /** @var Address $shipping */
        $shipping = $this->om->create(Address::class);
        $shipping->setAddressType(Address::TYPE_SHIPPING)
            ->setFirstname('Max')
            ->setLastname('Mustermann')
            ->setCompany($companyName)
            ->setStreet(['Musterstraße 1'])
            ->setCity('Berlin')
            ->setPostcode('10115')
            ->setCountryId('DE')
            ->setTelephone('+49300000000');

        $order->setBillingAddress($billing);
        $order->setShippingAddress($shipping);

        /** @var Item $item */
        $item = $this->om->create(Item::class);
        $item->setProductId(1)
            ->setSku('TEST-SKU-001')
            ->setName('Test Product')
            ->setQtyOrdered(1)
            ->setBasePrice(100.00)
            ->setPrice(100.00)
            ->setBaseRowTotal(100.00)
            ->setRowTotal(100.00)
            ->setBaseTaxAmount(19.00)
            ->setTaxAmount(19.00)
            ->setProductType('simple');

        $order->addItem($item);
        $this->orderRepository->save($order);

        return $order;
    }

    /**
     * Calls the CreateAsyncOrder request handler and stores the Mondu UUID on
     * the order + mondu_transactions table.
     *
     * @param OrderInterface $order
     * @return string  The Mondu order UUID.
     * @throws \Exception
     */
    protected function createMonduAsyncOrder(OrderInterface $order): string
    {
        $storeId = (int) $order->getStoreId();

        $result = $this->om->get(RequestFactory::class)
            ->create(RequestFactory::CREATE_ASYNC_ORDER, $storeId)
            ->process(['order' => $order]);

        $orderData = $result['order'];
        $orderUuid = $orderData['uuid'];

        $order->setData('mondu_reference_id', $orderUuid);
        $order->addCommentToStatusHistory('Mondu test: async order created ' . $orderUuid);
        $this->orderRepository->save($order);

        $this->logHelper->logTransaction($order, $orderData, null, 'mondu', 'async');

        return $orderUuid;
    }

    // -----------------------------------------------------------------------
    // Webhook helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a webhook params array for direct handler invocation.
     *
     * @param string $topic                 e.g. 'order/pending'
     * @param string $orderUuid             Mondu order UUID
     * @param string $externalReferenceId   M2 increment_id or M2_ASYNC_* placeholder
     * @param array  $extra                 Additional payload fields (e.g. bank_account)
     * @return array
     */
    protected function webhookParams(
        string $topic,
        string $orderUuid,
        string $externalReferenceId,
        array $extra = []
    ): array {
        return array_merge([
            'topic'                => $topic,
            'order_uuid'           => $orderUuid,
            'external_reference_id' => $externalReferenceId,
            'order_state'          => $this->topicToState($topic),
        ], $extra);
    }

    /**
     * Derives a Mondu order_state string from a topic string.
     */
    private function topicToState(string $topic): string
    {
        $map = [
            'order/pending'    => 'pending',
            'order/authorized' => 'authorized',
            'order/confirmed'  => 'confirmed',
            'order/declined'   => 'declined',
            'order/canceled'   => 'canceled',
        ];
        return $map[$topic] ?? 'unknown';
    }

    /**
     * Reloads an order from the database to get the latest persisted state.
     *
     * @param int $orderId
     * @return OrderInterface
     */
    protected function reloadOrder(int $orderId): OrderInterface
    {
        // Clear the ORM cache so we read fresh from DB
        $this->om->get(\Magento\Sales\Model\ResourceModel\Order::class)
            ->load($this->om->create(Order::class), $orderId);

        return $this->orderRepository->get($orderId);
    }

    // -----------------------------------------------------------------------
    // Webhook secret helper
    // -----------------------------------------------------------------------

    /**
     * Returns the decrypted webhook secret for the first configured website.
     */
    protected function getWebhookSecret(): string
    {
        if (self::$webhookSecret !== null) {
            return self::$webhookSecret;
        }

        /** @var \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig */
        $scopeConfig = $this->om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int) $website->getId();
            if ($websiteId === 0) {
                continue;
            }
            $isSandbox = $scopeConfig->isSetFlag(
                'payment/mondu/sandbox',
                ScopeInterface::SCOPE_WEBSITE,
                $websiteId
            );
            $mode = $isSandbox ? 'sandbox' : 'live';
            $encrypted = $scopeConfig->getValue(
                'payment/mondu/' . $mode . '_webhook_secret',
                ScopeInterface::SCOPE_WEBSITE,
                $websiteId
            );
            if (!empty($encrypted)) {
                $secret = $this->encryptor->decrypt($encrypted);
                if (!empty($secret)) {
                    self::$webhookSecret = $secret;
                    return self::$webhookSecret;
                }
            }
        }

        $this->markTestSkipped('No webhook secret configured. Register webhooks via Mondu config first.');
        return '';
    }

    /**
     * Signs a JSON-encoded webhook payload with HMAC-SHA256.
     *
     * @param string $jsonPayload
     * @return string  The hex-encoded signature.
     */
    protected function signWebhook(string $jsonPayload): string
    {
        return hash_hmac('sha256', $jsonPayload, $this->getWebhookSecret());
    }

    // -----------------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------------

    /**
     * Deletes the test order and its Mondu transaction from the database.
     *
     * @param int    $orderId   entity_id from sales_order
     * @param string $orderUuid Mondu UUID (reference_id in mondu_transactions)
     */
    protected function cleanup(int $orderId, string $orderUuid): void
    {
        $connection = $this->resource->getConnection();

        // Delete mondu_transactions row
        $connection->delete(
            $this->resource->getTableName('mondu_transactions'),
            ['reference_id = ?' => $orderUuid]
        );

        // Delete mondu_transaction_items rows
        // (FK to mondu_transactions; delete by order entity_id as a fallback)
        $logIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('mondu_transactions'), ['entity_id'])
                ->where('order_id = ?', $orderId)
        );
        if ($logIds) {
            $connection->delete(
                $this->resource->getTableName('mondu_transaction_items'),
                ['mondu_transaction_id IN (?)' => $logIds]
            );
        }

        // Sales child tables — FK column names differ per table
        $connection->delete(
            $this->resource->getTableName('sales_order_item'),
            ['order_id = ?' => $orderId]
        );
        foreach (['sales_order_address', 'sales_order_payment', 'sales_order_status_history'] as $table) {
            $connection->delete(
                $this->resource->getTableName($table),
                ['parent_id = ?' => $orderId]
            );
        }
        $connection->delete(
            $this->resource->getTableName('sales_order'),
            ['entity_id = ?' => $orderId]
        );
    }
}