<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

/**
 * Reads the net terms the merchant may use from GET /api/v1/payment_terms.
 *
 * Used to populate a net term selector instead of offering an arbitrary number that
 * the API would then reject with "proposed net terms is not available for merchant".
 *
 * The read is always scoped to one order source. By default the API merges the terms
 * of every order source the merchant has, so an unscoped read can offer a term the
 * merchant only holds for a different flow. Callers pass SOURCE_ASYNC for the admin
 * order-create screen and SOURCE_WIDGET for the storefront checkout.
 *
 * The optional payment method narrows it further: a merchant's terms differ per
 * payment method, and order creation validates the term against both the source and
 * the method. Pass it wherever the method is already known.
 */
class PaymentTerms
{
    /**
     * Admin order-create screen, orders created through POST /orders/create_async.
     */
    public const SOURCE_ASYNC = 'async';

    /**
     * Storefront checkout. The API has no separate "hosted" source: a hosted
     * checkout order is looked up as a widget order.
     */
    public const SOURCE_WIDGET = 'widget';

    /**
     * Preselected whenever the merchant may use it.
     */
    public const PREFERRED_NET_TERM = 30;

    private const CACHE_KEY_PREFIX = 'mondu_payment_terms_';
    private const CACHE_TAG = 'MONDU_PAYMENT_TERMS';
    private const CACHE_LIFETIME = 3600;

    /**
     * @param CacheInterface $cache
     * @param RequestFactory $requestFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RequestFactory $requestFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Returns the raw payment terms list ([['net_term' => 30, 'country_code' => 'DE'], …]).
     *
     * @param string $source One of SOURCE_ASYNC, SOURCE_WIDGET
     * @param int|null $storeId
     * @param string|null $paymentMethod Mondu payment method identifier, e.g. "invoice"
     * @return array<int, array{net_term?: int, country_code?: string}>
     */
    public function getPaymentTerms(
        string $source,
        ?int $storeId = null,
        ?string $paymentMethod = null
    ): array {
        $cacheKey = $this->getCacheKey($source, $storeId, $paymentMethod);

        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            $terms = $this->serializer->unserialize($cached);
            return is_array($terms) ? $terms : [];
        }

        $params = ['source' => $source];
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $params['payment_method'] = $paymentMethod;
        }

        try {
            $terms = $this->requestFactory->create(RequestFactory::PAYMENT_TERMS, $storeId)->process($params);
            $terms = is_array($terms) ? $terms : [];
        } catch (Exception $e) {
            $terms = [];
        }

        $this->cache->save(
            $this->serializer->serialize($terms),
            $cacheKey,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $terms;
    }

    /**
     * Returns the sorted, unique net terms available, optionally for one country only.
     *
     * A country the merchant holds no terms for yields an empty list rather than the
     * terms of the other countries: the API validates the term against the buyer's
     * country at order creation, so offering another country's term only produces a
     * 422 later on.
     *
     * @param string $source One of SOURCE_ASYNC, SOURCE_WIDGET
     * @param string|null $countryCode ISO-2 country code, e.g. "DE"
     * @param int|null $storeId
     * @param string|null $paymentMethod Mondu payment method identifier, e.g. "invoice"
     * @return int[]
     */
    public function getNetTerms(
        string $source,
        ?string $countryCode = null,
        ?int $storeId = null,
        ?string $paymentMethod = null
    ): array {
        $terms = $this->getPaymentTerms($source, $storeId, $paymentMethod);

        return $this->collectNetTerms($terms, $countryCode);
    }

    /**
     * The net term a form should preselect for the given country.
     *
     * 30 days is the house default, but the merchant may not have it for every
     * country, so this falls back to the available term closest to it, preferring
     * the shorter one on a tie.
     *
     * @param string $source One of SOURCE_ASYNC, SOURCE_WIDGET
     * @param string|null $countryCode ISO-2 country code, e.g. "DE"
     * @param int|null $storeId
     * @param string|null $paymentMethod Mondu payment method identifier, e.g. "invoice"
     * @return int|null
     */
    public function getDefaultNetTerm(
        string $source,
        ?string $countryCode = null,
        ?int $storeId = null,
        ?string $paymentMethod = null
    ): ?int {
        return $this->pickDefault($this->getNetTerms($source, $countryCode, $storeId, $paymentMethod));
    }

    /**
     * The term to preselect out of an already narrowed list.
     *
     * Shared with the storefront, where the list is the merchant's enabled terms
     * intersected with what the buyer's country allows, not a fresh API read.
     *
     * @param int[] $netTerms
     * @return int|null
     */
    public function pickDefault(array $netTerms): ?int
    {
        if ($netTerms === []) {
            return null;
        }
        if (in_array(self::PREFERRED_NET_TERM, $netTerms, true)) {
            return self::PREFERRED_NET_TERM;
        }

        $closest = null;
        foreach ($netTerms as $netTerm) {
            $isCloser = $closest === null
                || abs($netTerm - self::PREFERRED_NET_TERM) < abs($closest - self::PREFERRED_NET_TERM);
            if ($isCloser) {
                $closest = $netTerm;
            }
        }

        return $closest;
    }

    /**
     * Extracts the sorted, unique net terms from an API payment terms list.
     *
     * Rows without a country_code count for every country.
     *
     * @param array $terms Rows as returned by the API: [['net_term' => 30, 'country_code' => 'DE'], …]
     * @param string|null $countryCode ISO-2 country code, or null for no filtering
     * @return int[]
     */
    private function collectNetTerms(array $terms, ?string $countryCode): array
    {
        $netTerms = [];

        foreach ($terms as $term) {
            if (!isset($term['net_term'])) {
                continue;
            }
            $matchesCountry = $countryCode === null
                || !isset($term['country_code'])
                || strcasecmp((string) $term['country_code'], $countryCode) === 0;
            if (!$matchesCountry) {
                continue;
            }
            $netTerms[] = (int) $term['net_term'];
        }

        $netTerms = array_values(array_unique($netTerms));
        sort($netTerms);

        return $netTerms;
    }

    /**
     * Cache key for one source, store and payment method combination.
     *
     * The source and the payment method are part of the key: the same store reads
     * this endpoint for the admin async screen and for the storefront, and the two
     * answers must not overwrite each other.
     *
     * @param string $source
     * @param int|null $storeId
     * @param string|null $paymentMethod
     * @return string
     */
    private function getCacheKey(string $source, ?int $storeId, ?string $paymentMethod): string
    {
        return self::CACHE_KEY_PREFIX
            . ($storeId ?? 'default')
            . '_' . $source
            . '_' . ($paymentMethod ?? 'any');
    }

    /**
     * Drops the cached payment terms of every source, store and payment method.
     *
     * Called when the configuration changes, so the API key it was read with may
     * no longer be the current one.
     *
     * @return void
     */
    public function resetCache(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }
}
