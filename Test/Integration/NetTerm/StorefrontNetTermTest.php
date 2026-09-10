<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\NetTerm;

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Mondu\Mondu\Helpers\PaymentTerms;
use Mondu\Mondu\Model\Payment\AsyncOrderFields as F;
use Mondu\Mondu\Model\Request\Transactions;
use Mondu\Mondu\Model\Ui\NetTermConfigProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the net terms a buyer may choose from in the storefront checkout.
 *
 * Behaviour verified against the sandbox and encoded here:
 *  - a term is only offered when the merchant enabled it AND the buyer's country
 *    allows it, because order creation validates the term against the country and
 *    answers 422 "proposed net terms is not available for merchant" otherwise
 *  - a country the merchant holds no terms for offers nothing rather than falling
 *    back to another country's terms
 *  - only invoice and direct debit are settled on a term at all, and a term left
 *    over from one of them never travels with an instalment or pay now order
 *  - the term the buyer picked travels on the quote payment, the same field the
 *    admin order-create form uses, so it is on the order for the invoice PDF
 */
class StorefrontNetTermTest extends TestCase
{
    private $om;

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();
    }

    public function testOnlyEnabledTermsReachTheCheckout(): void
    {
        $byCountry = $this->mapTermsByCountry(
            [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60, 'country_code' => 'DE'],
                ['net_term' => 90, 'country_code' => 'DE'],
                ['net_term' => 30, 'country_code' => 'FR'],
            ],
            [30, 60]
        );

        $this->assertSame([30, 60], $byCountry['DE'], '90 was not enabled by the merchant');
        $this->assertSame([30], $byCountry['FR'], 'FR only holds 30');
    }

    public function testCountryWithoutTermsIsAbsentRatherThanBorrowingAnother(): void
    {
        $byCountry = $this->mapTermsByCountry(
            [['net_term' => 30, 'country_code' => 'DE']],
            [30]
        );

        $this->assertArrayNotHasKey(
            'NL',
            $byCountry,
            'offering another country term only earns a 422 at order creation'
        );
    }

    public function testRowsWithoutCountryCountEverywhere(): void
    {
        $byCountry = $this->mapTermsByCountry(
            [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60],
            ],
            [30, 60]
        );

        $this->assertSame([30, 60], $byCountry['DE'], 'the country-less term applies to DE too');
        $this->assertSame([60], $byCountry['*'], 'and stays reachable for a country listed nowhere');
    }

    public function testDuplicateApiRowsAreCollapsed(): void
    {
        $byCountry = $this->mapTermsByCountry(
            [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 30, 'country_code' => 'de'],
            ],
            [30]
        );

        $this->assertSame([30], $byCountry['DE'], 'the endpoint returns one row per order source, undeduplicated');
    }

    public function testPreferredTermIsThirtyThenTheClosestShorterOne(): void
    {
        $paymentTerms = $this->om->get(PaymentTerms::class);

        $this->assertSame(30, $paymentTerms->pickDefault([14, 30, 60]));
        $this->assertSame(14, $paymentTerms->pickDefault([14, 60]), '14 and 60 tie on distance, the shorter wins');
        $this->assertSame(45, $paymentTerms->pickDefault([45, 90]));
        $this->assertNull($paymentTerms->pickDefault([]));
    }

    public function testEachSourceAndMethodIsCachedApart(): void
    {
        $getCacheKey = new ReflectionMethod(PaymentTerms::class, 'getCacheKey');
        $paymentTerms = $this->om->get(PaymentTerms::class);

        $keys = [
            $getCacheKey->invoke($paymentTerms, PaymentTerms::SOURCE_WIDGET, 1, null),
            $getCacheKey->invoke($paymentTerms, PaymentTerms::SOURCE_ASYNC, 1, null),
            $getCacheKey->invoke($paymentTerms, PaymentTerms::SOURCE_WIDGET, 2, null),
            $getCacheKey->invoke($paymentTerms, PaymentTerms::SOURCE_WIDGET, 1, 'invoice'),
        ];

        $this->assertCount(
            4,
            array_unique($keys),
            'the same store reads this endpoint for several sources and methods, the answers must not overwrite each other'
        );
    }

    public function testSelectedTermTravelsOnTheQuotePayment(): void
    {
        $quote = $this->om->create(Quote::class);
        $quote->getPayment()->setMethod('mondu');
        $quote->getPayment()->setAdditionalInformation(F::FIELD_NET_TERM, 60);

        $this->assertSame(60, $this->getSelectedNetTerm($quote));
    }

    public function testOnlyInvoiceAndDirectDebitAreSettledOnATerm(): void
    {
        $this->assertTrue(F::takesNetTerm('mondu'));
        $this->assertTrue(F::takesNetTerm('mondusepa'));
        $this->assertFalse(F::takesNetTerm('monduinstallment'));
        $this->assertFalse(F::takesNetTerm('monduinstallmentbyinvoice'));
        $this->assertFalse(F::takesNetTerm('mondupaynow'));
        $this->assertFalse(F::takesNetTerm(''), 'an unset method must not carry a term either');
    }

    public function testTermLeftOverFromAnotherMethodIsNotSent(): void
    {
        // The buyer picks invoice, gets a term, then switches to instalments. The
        // term stays on the quote payment, and sending it is refused with 422
        // "proposed net terms is not available for merchant", which used to make
        // the instalment order fail to reach Mondu at all.
        $quote = $this->om->create(Quote::class);
        $quote->getPayment()->setMethod('monduinstallment');
        $quote->getPayment()->setAdditionalInformation(F::FIELD_NET_TERM, 60);

        $this->assertNull($this->getSelectedNetTerm($quote));
    }

    public function testNoTermIsSentWhenTheBuyerPickedNone(): void
    {
        $quote = $this->om->create(Quote::class);
        $quote->getPayment()->setMethod('mondu');

        $this->assertNull(
            $this->getSelectedNetTerm($quote),
            'without a term the account keeps applying the one it applied before this was configurable'
        );
    }

    public function testGarbageTermIsIgnoredRatherThanSent(): void
    {
        $quote = $this->om->create(Quote::class);
        $quote->getPayment()->setMethod('mondu');
        $quote->getPayment()->setAdditionalInformation(F::FIELD_NET_TERM, 'thirty');

        $this->assertNull($this->getSelectedNetTerm($quote));
    }

    /**
     * @param array $rows
     * @param int[] $available
     * @return array<string, int[]>
     */
    private function mapTermsByCountry(array $rows, array $available): array
    {
        $method = new ReflectionMethod(NetTermConfigProvider::class, 'mapTermsByCountry');

        return $method->invoke($this->om->create(NetTermConfigProvider::class), $rows, $available);
    }

    /**
     * @param Quote $quote
     * @return int|null
     */
    private function getSelectedNetTerm(Quote $quote): ?int
    {
        $method = new ReflectionMethod(Transactions::class, 'getSelectedNetTerm');

        return $method->invoke($this->om->create(Transactions::class), $quote);
    }
}
