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
 * The offered terms are narrowed the same way order creation validates them, by
 * payment method and by country, otherwise the checkout offers a term the API then
 * refuses with 422 "proposed net terms is not available for merchant".
 *
 * The country is not settled when the checkout config is generated and can change
 * without a page reload, so a map goes out rather than one ready made list and the
 * payment method renderer reads its own entry whenever the address changes. Methods
 * that are not settled on a term at all, instalments and pay now, are absent from
 * the map, so their checkout offers nothing and sends nothing.
 *
 * Everything stays empty unless the merchant enabled terms in the configuration,
 * which keeps the checkout sending no net term at all, as it did before.
 */
class NetTermConfigProvider implements ConfigProviderInterface
{
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
        $available = $this->configProvider->getAvailableNetTerms($storeId);

        if ($available === []) {
            return ['monduNetTerms' => ['available' => [], 'byMethod' => []]];
        }

        return [
            'monduNetTerms' => [
                'available' => $available,
                'byMethod' => $this->getTermsByMethod($available, $storeId),
            ],
        ];
    }

    /**
     * Country maps of the enabled terms, per Magento payment method code.
     *
     * Only the methods settled on a term get an entry, and each is read with its own
     * Mondu identifier so the API can narrow the answer to that method as well.
     *
     * @param int[] $available
     * @param int|null $storeId
     * @return array<string, array<string, int[]>>
     */
    private function getTermsByMethod(array $available, ?int $storeId): array
    {
        $byMethod = [];

        foreach (PaymentMethod::MAPPING as $monduMethod => $methodCode) {
            if (!AsyncOrderFields::takesNetTerm($methodCode)) {
                continue;
            }

            $byMethod[$methodCode] = $this->getTermsByCountry($available, $storeId, (string) $monduMethod);
        }

        return $byMethod;
    }

    /**
     * Which of the enabled terms each country allows.
     *
     * Rows the API returns without a country count for every country, so they are
     * added to each country the merchant has, and kept under a wildcard key for a
     * buyer whose country appears nowhere else.
     *
     * @param int[] $available
     * @param int|null $storeId
     * @param string $monduMethod Mondu payment method identifier, e.g. "invoice"
     * @return array<string, int[]>
     */
    private function getTermsByCountry(array $available, ?int $storeId, string $monduMethod): array
    {
        return $this->mapTermsByCountry(
            $this->paymentTerms->getPaymentTerms(PaymentTerms::SOURCE_WIDGET, $storeId, $monduMethod),
            $available
        );
    }

    /**
     * Groups the API rows by country, dropping every term the merchant did not enable.
     *
     * @param array<int, array{net_term?: int, country_code?: string}> $rows
     * @param int[] $available
     * @return array<string, int[]>
     */
    private function mapTermsByCountry(array $rows, array $available): array
    {
        $byCountry = [];
        $anyCountry = [];

        foreach ($rows as $row) {
            if (!isset($row['net_term'])) {
                continue;
            }
            $netTerm = (int) $row['net_term'];
            if (!in_array($netTerm, $available, true)) {
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
            $byCountry['*'] = $this->normalise($anyCountry);
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
