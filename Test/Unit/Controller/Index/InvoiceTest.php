<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Controller\Index;

use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice as OrderInvoice;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Controller\Index\Invoice;
use Mondu\Mondu\Model\Pdf\InvoicePdfRendererInterface;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InvoiceTest extends TestCase
{
    private const ORDER_UID = '5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b';
    private const INVOICE_ID = '000000042';

    /**
     * @var ConfigProvider
     */
    private ConfigProvider $configProvider;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var RequestInterface|MockObject
     */
    private $request;

    /**
     * @var array
     */
    private array $params = [];

    protected function setUp(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(
            static fn ($data) => hash_hmac('sha256', (string) $data, 'deployment-key')
        );
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->configProvider = new ConfigProvider(
            $encryptor,
            $this->createMock(ResourceConfig::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $urlBuilder,
            $this->createMock(WriterInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(fn ($key) => $this->params[$key] ?? null);

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    public function testLinkFromGetPdfUrlDownloadsTheInvoice(): void
    {
        $url = $this->configProvider->getPdfUrl(self::ORDER_UID, self::INVOICE_ID);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $this->params);

        $this->expectOrderLookup();
        $result = $this->createController()->execute();

        $this->assertInstanceOf(Raw::class, $result);
    }

    /**
     * @dataProvider unauthorizedParams
     * @param array $params
     */
    public function testRequestWithoutAValidTokenIsNotFound(array $params): void
    {
        $this->params = $params;

        $this->orderRepository->expects($this->never())->method('getList');
        $this->expectException(NotFoundException::class);

        $this->createController()->execute();
    }

    /**
     * @return array
     */
    public function unauthorizedParams(): array
    {
        $signed = 'mondu_invoice_pdf|' . self::ORDER_UID . '|' . self::INVOICE_ID;
        $validToken = hash_hmac('sha256', $signed, 'deployment-key');

        return [
            'order and invoice only, the old link' => [
                ['id' => self::ORDER_UID, 'r' => self::INVOICE_ID],
            ],
            'empty token' => [
                ['id' => self::ORDER_UID, 'r' => self::INVOICE_ID, 't' => ''],
            ],
            'made up token' => [
                ['id' => self::ORDER_UID, 'r' => self::INVOICE_ID, 't' => str_repeat('a', 64)],
            ],
            'token of another invoice of the same order' => [
                ['id' => self::ORDER_UID, 'r' => '000000043', 't' => $validToken],
            ],
            'token signed with another key' => [
                [
                    'id' => self::ORDER_UID,
                    'r' => self::INVOICE_ID,
                    't' => hash_hmac('sha256', $signed, 'another-key'),
                ],
            ],
            'token as an array' => [
                ['id' => self::ORDER_UID, 'r' => self::INVOICE_ID, 't' => [$validToken]],
            ],
        ];
    }

    /**
     * Wires the order repository to return one order with the requested invoice.
     */
    private function expectOrderLookup(): void
    {
        $invoice = $this->createMock(OrderInvoice::class);
        $invoice->method('getIncrementId')->willReturn(self::INVOICE_ID);

        $order = $this->createMock(Order::class);
        $order->method('getInvoiceCollection')->willReturn([$invoice]);
        $order->method('getStoreId')->willReturn(1);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->expects($this->once())->method('getList')->willReturn($searchResult);
    }

    /**
     * @return Invoice
     */
    private function createController(): Invoice
    {
        $raw = $this->createMock(Raw::class);
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();

        $rawFactory = $this->getMockBuilder(RawFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $rawFactory->method('create')->willReturn($raw);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $pdfRenderer = $this->createMock(InvoicePdfRendererInterface::class);
        $pdfRenderer->method('render')->willReturn('%PDF-1.4');

        return new Invoice(
            $this->orderRepository,
            $pdfRenderer,
            $rawFactory,
            $this->request,
            $searchCriteriaBuilder,
            $this->createMock(AppEmulation::class),
            $this->configProvider,
        );
    }
}
