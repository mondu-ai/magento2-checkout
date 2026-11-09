<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\NetTerm;

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Mondu\Mondu\Helpers\PaymentTerms;
use Mondu\Mondu\Model\Payment\AsyncOrderFields as F;
use Mondu\Mondu\Model\Request\Transactions;
use Mondu\Mondu\Model\Ui\ConfigProvider;
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
 *  - the server sends a posted term only when the checkout would have offered it,
 *    so a hand-crafted request cannot put an order on a term the merchant did not
 *    enable or the billing country does not allow
 */
class StorefrontNetTermTest extends TestCase
{
    /**
     * Object manager used to build real services.
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $om;

    private const ALL_ENABLED = ['mondu' => [30, 60], 'mondusepa' => [30, 60], 'mondupaynow' => [3]];

    private const DE_ROWS = [
        ['net_term' => 30, 'country_code' => 'DE'],
        ['net_term' => 60, 'country_code' => 'DE'],
    ];

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();
    }

    public function testTermsOfOneMethodNeverLeakIntoAnother(): void
    {
        // The account holds 3 days for pay now only, and invoice orders on 3 days
        // are refused. Because the terms endpoint answers a list merged across
        // methods wherever its filter is not live yet, what the merchant enabled
        // per method is what keeps them apart.
        $forInvoice = $this->mapTermsByCountry(
            [
                ['net_term' => 3, 'country_code' => 'DE'],
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60, 'country_code' => 'DE'],
            ],
            [30, 60]
        );

        $this->assertSame([30, 60], $forInvoice['DE']);
        $this->assertNotContains(3, $forInvoice['DE'], '3 days belongs to pay now, invoice is refused on it');
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
            'the same store reads this endpoint for several sources and methods, '
            . 'the answers must not overwrite each other'
        );
    }

    public function testSelectedTermTravelsOnTheQuotePayment(): void
    {
        $quote = $this->makeQuote('mondu', 60, 'DE');

        $this->assertSame(
            60,
            $this->getSelectedNetTerm($quote, ['mondu' => [30, 60]], [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60, 'country_code' => 'DE'],
            ])
        );
    }

    public function testTermTheMerchantDidNotEnableIsNotSent(): void
    {
        $quote = $this->makeQuote('mondu', 90, 'DE');

        $this->assertNull(
            $this->getSelectedNetTerm($quote, ['mondu' => [30, 60]], [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60, 'country_code' => 'DE'],
                ['net_term' => 90, 'country_code' => 'DE'],
            ]),
            'the account holds 90, but the merchant did not enable it for invoice'
        );
    }

    public function testNoTermIsSentWhenTheMerchantEnabledNone(): void
    {
        $quote = $this->makeQuote('mondu', 60, 'DE');

        $this->assertNull(
            $this->getSelectedNetTerm($quote, [], [['net_term' => 60, 'country_code' => 'DE']]),
            'no terms configured means the account keeps applying its own term'
        );
    }

    public function testTermOfAnotherCountryIsNotSent(): void
    {
        $quote = $this->makeQuote('mondu', 60, 'FR');

        $this->assertNull(
            $this->getSelectedNetTerm($quote, ['mondu' => [30, 60]], [
                ['net_term' => 30, 'country_code' => 'FR'],
                ['net_term' => 60, 'country_code' => 'DE'],
            ]),
            '60 is only allowed in DE, the FR buyer would get a 422'
        );
    }

    public function testTermEnabledForAnotherMethodOnlyIsNotSent(): void
    {
        $quote = $this->makeQuote('mondusepa', 60, 'DE');

        $this->assertNull(
            $this->getSelectedNetTerm($quote, ['mondu' => [60], 'mondusepa' => [30]], [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60, 'country_code' => 'DE'],
            ])
        );
    }

    public function testCountrylessTermIsSentForAnyCountry(): void
    {
        $quote = $this->makeQuote('mondu', 60, 'NL');

        $this->assertSame(
            60,
            $this->getSelectedNetTerm($quote, ['mondu' => [30, 60]], [
                ['net_term' => 30, 'country_code' => 'DE'],
                ['net_term' => 60],
            ])
        );
    }

    public function testOnlyTheMethodsTheApiAcceptsATermOnCarryOne(): void
    {
        // Verified by creating sandbox orders: pay now takes the short term the
        // account holds for it, while instalments are refused for every term.
        // Pay now requires no fields, so this cannot come from REQUIRED_BY_METHOD.
        $this->assertTrue(F::takesNetTerm('mondu'));
        $this->assertTrue(F::takesNetTerm('mondusepa'));
        $this->assertTrue(F::takesNetTerm('mondupaynow'));
        $this->assertFalse(F::takesNetTerm('monduinstallment'));
        $this->assertFalse(F::takesNetTerm('monduinstallmentbyinvoice'));
        $this->assertFalse(F::takesNetTerm(''), 'an unset method must not carry a term either');
    }

    public function testTermLeftOverFromAnotherMethodIsNotSent(): void
    {
        // The buyer picks invoice, gets a term, then switches to instalments. The
        // term stays on the quote payment, and sending it is refused with 422
        // "proposed net terms is not available for merchant", which used to make
        // the instalment order fail to reach Mondu at all.
        $quote = $this->makeQuote('monduinstallment', 60, 'DE');

        $this->assertNull($this->getSelectedNetTerm($quote, self::ALL_ENABLED, self::DE_ROWS));
    }

    public function testNoTermIsSentWhenTheBuyerPickedNone(): void
    {
        $quote = $this->makeQuote('mondu', null, 'DE');

        $this->assertNull(
            $this->getSelectedNetTerm($quote, self::ALL_ENABLED, self::DE_ROWS),
            'without a term the account keeps applying the one it applied before this was configurable'
        );
    }

    public function testGarbageTermIsIgnoredRatherThanSent(): void
    {
        $quote = $this->makeQuote('mondu', 'thirty', 'DE');

        $this->assertNull($this->getSelectedNetTerm($quote, self::ALL_ENABLED, self::DE_ROWS));
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
     * @param string $method
     * @param int|string|null $netTerm
     * @param string $countryId
     * @return Quote
     */
    private function makeQuote(string $method, int|string|null $netTerm, string $countryId): Quote
    {
        $quote = $this->om->create(Quote::class);
        $quote->getPayment()->setMethod($method);
        if ($netTerm !== null) {
            $quote->getPayment()->setAdditionalInformation(F::FIELD_NET_TERM, $netTerm);
        }
        $quote->getBillingAddress()->setCountryId($countryId);

        return $quote;
    }

    /**
     * Runs Transactions::getSelectedNetTerm with a stubbed merchant config and terms endpoint.
     *
     * @param Quote $quote
     * @param array<string, int[]> $configuredByMethod
     * @param array $apiRows
     * @return int|null
     */
    private function getSelectedNetTerm(Quote $quote, array $configuredByMethod, array $apiRows): ?int
    {
        $configProvider = $this->createStub(ConfigProvider::class);
        $configProvider->method('getAvailableNetTerms')
            ->willReturnCallback(fn (string $method) => $configuredByMethod[$method] ?? []);
        $paymentTerms = $this->createStub(PaymentTerms::class);
        $paymentTerms->method('getPaymentTerms')->willReturn($apiRows);

        $netTermConfigProvider = $this->om->create(NetTermConfigProvider::class, [
            'configProvider' => $configProvider,
            'paymentTerms' => $paymentTerms,
        ]);
        $transactions = $this->om->create(Transactions::class, ['netTermConfigProvider' => $netTermConfigProvider]);

        $method = new ReflectionMethod(Transactions::class, 'getSelectedNetTerm');

        return $method->invoke($transactions, $quote);
    }
}
