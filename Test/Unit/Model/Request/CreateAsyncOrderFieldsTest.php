<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Locale\Resolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Model\Request\CreateAsyncOrder;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the two helpers added for admin-only payment-method-specific fields:
 *   - applyPaymentMethodFields (top-level: net_term, number_of_installments)
 *   - applyBuyerPaymentFields  (buyer.*: legal_form, iban, account_holder)
 *
 * Invokes the private methods via reflection because they're internal to
 * CreateAsyncOrder::buildPayload but carry most of the logic we want to pin.
 */
class CreateAsyncOrderFieldsTest extends TestCase
{
    private CreateAsyncOrder $subject;
    private ReflectionClass $refl;

    protected function setUp(): void
    {
        $this->subject = new CreateAsyncOrder(
            $this->createMock(Curl::class),
            $this->createMock(ConfigProvider::class),
            $this->createMock(MonduFileLogger::class),
            $this->createMock(OrderHelper::class),
            $this->createMock(Resolver::class),
            $this->createMock(UrlBuilder::class),
        );
        $this->refl = new ReflectionClass(CreateAsyncOrder::class);
    }

    public function testNetTermAddedForInvoiceMethod(): void
    {
        $order = $this->makeOrder('mondu', [
            AsyncOrderFields::FIELD_NET_TERM => '30',
        ]);
        $payload = [];
        $this->invoke('applyPaymentMethodFields', $order, $payload);

        $this->assertSame(30, $payload['net_term']);
        $this->assertArrayNotHasKey('number_of_installments', $payload);
    }

    public function testNumberOfInstallmentsAddedForInstallmentMethod(): void
    {
        $order = $this->makeOrder('monduinstallment', [
            AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS => '6',
        ]);
        $payload = [];
        $this->invoke('applyPaymentMethodFields', $order, $payload);

        $this->assertSame(6, $payload['number_of_installments']);
        $this->assertArrayNotHasKey('net_term', $payload);
    }

    public function testNetTermIgnoredForInstallmentMethodEvenIfProvided(): void
    {
        $order = $this->makeOrder('monduinstallment', [
            AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS => '3',
            AsyncOrderFields::FIELD_NET_TERM => '30', // not in required list → skipped
        ]);
        $payload = [];
        $this->invoke('applyPaymentMethodFields', $order, $payload);

        $this->assertArrayNotHasKey('net_term', $payload);
        $this->assertSame(3, $payload['number_of_installments']);
    }

    public function testNonMonduMethodLeavesPayloadUntouched(): void
    {
        $order = $this->makeOrder('checkmo', [
            AsyncOrderFields::FIELD_NET_TERM => '30',
        ]);
        $payload = ['currency' => 'EUR'];
        $this->invoke('applyPaymentMethodFields', $order, $payload);

        $this->assertSame(['currency' => 'EUR'], $payload);
    }

    public function testBuyerFieldsForSepa(): void
    {
        $order = $this->makeOrder('mondusepa', [
            AsyncOrderFields::FIELD_LEGAL_FORM => 'GmbH',
            AsyncOrderFields::FIELD_IBAN => 'DE89 3704 0044 0532 0130 00',
            AsyncOrderFields::FIELD_ACCOUNT_HOLDER => 'John Doe',
        ]);
        $params = [];
        $this->invoke('applyBuyerPaymentFields', $order, $params);

        $this->assertSame('GmbH', $params['legal_form']);
        $this->assertSame('DE89370400440532013000', $params['iban'], 'IBAN must be stripped of whitespace');
        $this->assertSame('John Doe', $params['account_holder']);
    }

    public function testBuyerFieldsForInvoiceOnlyLegalForm(): void
    {
        $order = $this->makeOrder('mondu', [
            AsyncOrderFields::FIELD_LEGAL_FORM => 'AG',
            AsyncOrderFields::FIELD_IBAN => 'DE89370400440532013000',
        ]);
        $params = [];
        $this->invoke('applyBuyerPaymentFields', $order, $params);

        $this->assertSame('AG', $params['legal_form']);
        $this->assertArrayNotHasKey('iban', $params, 'IBAN not required for mondu/invoice');
        $this->assertArrayNotHasKey('account_holder', $params);
    }

    /**
     * @param array<string,mixed> $additional
     */
    private function makeOrder(string $methodCode, array $additional): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($methodCode);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            fn (string $k) => $additional[$k] ?? null
        );

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function invoke(string $methodName, OrderInterface $order, array &$arr): void
    {
        $method = $this->refl->getMethod($methodName);
        $method->setAccessible(true);

        // Reflection doesn't forward references for &$arr, so use closure binding.
        $closure = function (OrderInterface $order, array &$arr) use ($methodName) {
            $this->{$methodName}($order, $arr);
        };
        $closure->bindTo($this->subject, CreateAsyncOrder::class)($order, $arr);
    }
}
