<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Model\Payment;

use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use PHPUnit\Framework\TestCase;

class AsyncOrderFieldsTest extends TestCase
{
    public function testIsMonduMethodRecognisesAllFourMethods(): void
    {
        $this->assertTrue(AsyncOrderFields::isMonduMethod('mondu'));
        $this->assertTrue(AsyncOrderFields::isMonduMethod('mondusepa'));
        $this->assertTrue(AsyncOrderFields::isMonduMethod('monduinstallment'));
        $this->assertTrue(AsyncOrderFields::isMonduMethod('monduinstallmentbyinvoice'));
    }

    public function testIsMonduMethodRejectsPayNowAndOthers(): void
    {
        // mondupaynow is intentionally not registered — user scoped it out.
        $this->assertFalse(AsyncOrderFields::isMonduMethod('mondupaynow'));
        $this->assertFalse(AsyncOrderFields::isMonduMethod('checkmo'));
        $this->assertFalse(AsyncOrderFields::isMonduMethod(''));
    }

    /**
     * @dataProvider requiredFieldsProvider
     * @param string[] $expected
     */
    public function testRequiredFor(string $code, array $expected): void
    {
        $this->assertSame($expected, AsyncOrderFields::requiredFor($code));
    }

    public static function requiredFieldsProvider(): array
    {
        return [
            'invoice' => ['mondu', [
                AsyncOrderFields::FIELD_LEGAL_FORM,
                AsyncOrderFields::FIELD_NET_TERM,
            ]],
            'direct_debit' => ['mondusepa', [
                AsyncOrderFields::FIELD_LEGAL_FORM,
                AsyncOrderFields::FIELD_NET_TERM,
                AsyncOrderFields::FIELD_IBAN,
                AsyncOrderFields::FIELD_ACCOUNT_HOLDER,
            ]],
            'installment' => ['monduinstallment', [
                AsyncOrderFields::FIELD_LEGAL_FORM,
                AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS,
                AsyncOrderFields::FIELD_IBAN,
                AsyncOrderFields::FIELD_ACCOUNT_HOLDER,
            ]],
            'installment_by_invoice' => ['monduinstallmentbyinvoice', [
                AsyncOrderFields::FIELD_LEGAL_FORM,
                AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS,
            ]],
            'unknown' => ['paypal', []],
        ];
    }
}
