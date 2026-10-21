<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Controller\Webhooks;

use Laminas\Http\Headers;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Mondu\Mondu\Controller\Webhooks\Index;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Two websites with their own webhook secrets. Store 1 belongs to website 1, store 2 to website 2.
 */
class IndexTest extends TestCase
{
    private const SECRETS = [1 => 'secret-of-website-1', 2 => 'secret-of-website-2'];
    private const ORDER_UUID = '5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b';

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var MonduLogHelper|MockObject
     */
    private $monduLogHelper;

    /**
     * @var array
     */
    private array $response = [];

    /**
     * Store views running in sandbox mode, keyed by store id.
     *
     * @var array
     */
    private array $sandboxStores = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->monduLogHelper = $this->createMock(MonduLogHelper::class);
    }

    public function testWebhookSignedByTheOrderWebsiteChangesTheOrder(): void
    {
        $this->givenTransactionFor($this->createOrder(1, 'mondu'));

        $this->orderRepository->expects($this->once())->method('save');
        $this->sendPendingWebhook(self::SECRETS[1]);

        $this->assertSame(['message' => 'ok', 'error' => 0], $this->response);
    }

    public function testWebhookSignedByAnotherWebsiteIsIgnored(): void
    {
        $this->givenTransactionFor($this->createOrder(1, 'mondu'));

        $this->orderRepository->expects($this->never())->method('save');
        $this->sendPendingWebhook(self::SECRETS[2]);

        $this->assertSame(['message' => 'ok', 'error' => 0], $this->response);
    }

    public function testWebhookForAnOrderNotPaidWithMonduIsIgnored(): void
    {
        $this->givenTransactionFor($this->createOrder(1, 'checkmo'));

        $this->orderRepository->expects($this->never())->method('save');
        $this->sendPendingWebhook(self::SECRETS[1]);

        $this->assertSame(['message' => 'ok', 'error' => 0], $this->response);
    }

    public function testOrderFoundByIncrementIdMustBelongToTheMonduOrder(): void
    {
        $this->monduLogHelper->method('getTransactionByOrderUid')->willReturn([]);
        $this->givenIncrementIdLookupFinds($this->createOrder(1, 'mondu', 'uuid-of-another-mondu-order'));

        $this->orderRepository->expects($this->never())->method('save');
        $this->sendPendingWebhook(self::SECRETS[1]);

        $this->assertSame(['message' => 'ok', 'error' => 0], $this->response);
    }

    public function testSandboxModeIsReadAtTheOrderStoreView(): void
    {
        $this->sandboxStores = [1 => true];
        $this->givenTransactionFor($this->createOrder(1, 'mondu'));

        $this->orderRepository->expects($this->once())->method('save');
        $this->sendPendingWebhook('sandbox-' . self::SECRETS[1]);
    }

    public function testOrderFoundByIncrementIdWithoutStoredReferenceIsIgnored(): void
    {
        $this->monduLogHelper->method('getTransactionByOrderUid')->willReturn([]);
        $this->givenIncrementIdLookupFinds($this->createOrder(1, 'mondu', ''));

        $this->orderRepository->expects($this->never())->method('save');
        $this->sendPendingWebhook(self::SECRETS[1]);

        $this->assertSame(['message' => 'ok', 'error' => 0], $this->response);
    }

    public function testOrderFoundThroughTheTransactionWithoutStoredReferenceIsChanged(): void
    {
        $this->givenTransactionFor($this->createOrder(1, 'mondu', ''));

        $this->orderRepository->expects($this->once())->method('save');
        $this->sendPendingWebhook(self::SECRETS[1]);
    }

    public function testMissingOrderIsStillAnsweredForAnyWebsite(): void
    {
        $this->monduLogHelper->method('getTransactionByOrderUid')->willReturn([]);
        $this->givenIncrementIdLookupFinds(null);

        $this->orderRepository->expects($this->never())->method('save');
        $this->sendPendingWebhook(self::SECRETS[2]);

        $this->assertSame(['message' => 'Order does not exist', 'error' => 0], $this->response);
    }

    /**
     * @param int $storeId
     * @param string $paymentMethod
     * @param string $monduReferenceId
     * @return Order|MockObject
     */
    private function createOrder(int $storeId, string $paymentMethod, string $monduReferenceId = self::ORDER_UUID)
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getData')->willReturnMap([['mondu_reference_id', null, $monduReferenceId]]);

        return $order;
    }

    /**
     * @param Order $order
     */
    private function givenTransactionFor(Order $order): void
    {
        $this->monduLogHelper->method('getTransactionByOrderUid')->willReturn(['order_id' => 42]);
        $this->orderRepository->method('get')->with(42)->willReturn($order);
    }

    /**
     * @param Order|null $order
     */
    private function givenIncrementIdLookupFinds(?Order $order): void
    {
        $result = $this->createMock(OrderSearchResultInterface::class);
        $result->method('getTotalCount')->willReturn($order ? 1 : 0);
        $result->method('getItems')->willReturn($order ? [$order] : []);
        $this->orderRepository->method('getList')->willReturn($result);
    }

    /**
     * @param string $signingSecret
     */
    private function sendPendingWebhook(string $signingSecret): void
    {
        $content = json_encode([
            'topic' => 'order/pending',
            'order_uuid' => self::ORDER_UUID,
            'external_reference_id' => '000000042',
            'order_state' => 'pending',
        ]);

        $headers = $this->createMock(Headers::class);
        $headers->method('toArray')->willReturn([
            'X-Mondu-Signature' => hash_hmac('sha256', $content, $signingSecret),
        ]);
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn($content);
        $request->method('getHeaders')->willReturn($headers);

        $json = $this->createMock(Json::class);
        $json->method('setHttpResponseCode')->willReturnSelf();
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->response = $data;
            return $json;
        });
        $jsonFactory = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $jsonFactory->method('create')->willReturn($json);

        $filterBuilder = $this->createMock(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnSelf();
        $filterBuilder->method('setValue')->willReturnSelf();
        $filterBuilder->method('setConditionType')->willReturnSelf();
        $filterBuilder->method('create')->willReturn($this->createMock(Filter::class));
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilters')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        // Store ids equal website ids here; sandbox can only be switched on for a store view.
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path, $scope, $id) => $scope === 'store' && !empty($this->sandboxStores[$id])
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path, $scope, $id) => 'encrypted:'
                . (str_contains($path, 'sandbox_') ? 'sandbox-' : '') . self::SECRETS[$id]
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn ($value) => substr($value, strlen('encrypted:'))
        );

        (new Index(
            $this->createMock(ConfigProvider::class),
            $jsonFactory,
            $this->monduLogHelper,
            $this->createMock(MonduFileLogger::class),
            $this->orderRepository,
            $searchCriteriaBuilder,
            $filterBuilder,
            $request,
            new JsonSerializer(),
            $this->createStoreManager(),
            $scopeConfig,
            $encryptor,
            new PaymentMethod(
                $this->createMock(CacheInterface::class),
                $this->createMock(RequestFactory::class),
                new JsonSerializer()
            ),
        ))->execute();
    }

    /**
     * @return StoreManagerInterface|MockObject
     */
    private function createStoreManager()
    {
        $websites = [];
        $stores = [];
        foreach (array_keys(self::SECRETS) as $id) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($id);
            $stores[$id] = $store;

            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getDefaultStore')->willReturn($store);
            $websites[$id] = $website;
        }

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStore')->willReturnCallback(static fn ($id) => $stores[$id]);

        return $storeManager;
    }
}
