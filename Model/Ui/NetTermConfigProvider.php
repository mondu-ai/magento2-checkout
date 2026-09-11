<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Helpers\PaymentTerms;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;

/**
 * Feeds the storefront checkout the net terms a buyer may choose from.
 *
 * The terms are narrowed the same way order creation validates them, by payment
 * method and by country, or the checkout offers something the API then refuses
 * with 422 "proposed net terms is not available for merchant". Both dimensions
 * matter on a real account: the one behind this plugin holds 30, 60 and 90 days
 * for invoice but only 3 days for pay now, and only 30 days for France.
 *
 * Two sources are intersected rather than trusting either alone. The merchant's
 * per method configuration is authoritative about what to offer, and it is what
 * keeps an over broad reply from the terms endpoint out of the checkout while
 * its source and payment method filters are not yet everywhere. The endpoint is
 * authoritative about which country may use a term.
 *
 * The country is not settled when the checkout config is generated and can change
 * without a page reload, so a country map goes out rather than one ready made
 * list and the payment method renderer reads its own entry when the address
 * changes. Methods that cannot carry a term at all are absent from the map.
 */
class NetTermConfigProvider implements ConfigProviderInterface
{
    /**
     * Terms of a country the API lists without one, reachable for any buyer.
     */
    public const ANY_COUNTRY = '*';

    /**
     * @param CheckoutSession $checkoutSession
     * @param ConfigProvider $configProvider
     * @param PaymentTerms $paymentTerms
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ConfigProvider $configProvider,
        private readonly PaymentTerms $paymentTerms,
    ) {
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        $storeId = $this->getStoreId();
        $byMethod = [];

        foreach (AsyncOrderFields::NET_TERM_METHODS as $methodCode) {
            $configured = $this->configProvider->getAvailableNetTerms($methodCode, $storeId);
            if ($configured === []) {
                continue;
            }

            $monduMethod = array_search($methodCode, PaymentMethod::MAPPING, true);
            $byCountry = $this->mapTermsByCountry(
                $this->paymentTerms->getPaymentTerms(
                    PaymentTerms::SOURCE_WIDGET,
                    $storeId,
                    $monduMethod === false ? null : (string) $monduMethod
                ),
                $configured
            );

            if ($byCountry !== []) {
                $byMethod[$methodCode] = $byCountry;
            }
        }

        return ['monduNetTerms' => ['byMethod' => $byMethod]];
    }

    /**
     * Groups the terms by country, keeping only the ones the merchant enabled.
     *
     * Rows the API returns without a country count for every country, so they are
     * added to each country the merchant has, and kept under a wildcard key for a
     * buyer whose country appears nowhere else.
     *
     * @param array<int, array{net_term?: int, country_code?: string}> $rows
     * @param int[] $configured
     * @return array<string, int[]>
     */
    private function mapTermsByCountry(array $rows, array $configured): array
    {
        $byCountry = [];
        $anyCountry = [];

        foreach ($rows as $row) {
            if (!isset($row['net_term'])) {
                continue;
            }
            $netTerm = (int) $row['net_term'];
            if (!in_array($netTerm, $configured, true)) {
                continue;
            }

            $countryCode = isset($row['country_code']) ? strtoupper((string) $row['country_code']) : '';
            if ($countryCode === '') {
                $anyCountry[] = $netTerm;
                continue;
            }

            $byCountry[$countryCode][] = $netTerm;
        }

        foreach (array_keys($byCountry) as $countryCode) {
            $byCountry[$countryCode] = $this->normalise(array_merge($byCountry[$countryCode], $anyCountry));
        }

        if ($anyCountry !== []) {
            $byCountry[self::ANY_COUNTRY] = $this->normalise($anyCountry);
        }

        return $byCountry;
    }

    /**
     * @param int[] $netTerms
     * @return int[]
     */
    private function normalise(array $netTerms): array
    {
        $netTerms = array_values(array_unique($netTerms));
        sort($netTerms);

        return $netTerms;
    }

    /**
     * @return int|null
     */
    private function getStoreId(): ?int
    {
        $quote = $this->checkoutSession->getQuote();
        $storeId = $quote ? $quote->getStoreId() : null;

        return $storeId !== null ? (int) $storeId : null;
    }
}
