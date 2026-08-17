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
 * Used to populate the net term selector instead of letting the admin type an
 * arbitrary number that the API would then reject with "net term not available".
 */
class PaymentTerms
{
    /**
     * Used when the API is unreachable or answers with an empty list.
     */
    public const FALLBACK_NET_TERMS = [7, 14, 30, 60, 90];

    /**
     * Preselected in the admin form whenever the merchant may use it.
     */
    public const PREFERRED_NET_TERM = 30;

    private const CACHE_KEY_PREFIX = 'mondu_payment_terms_';
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
     * @param int|null $storeId
     * @return array<int, array{net_term?: int, country_code?: string}>
     */
    public function getPaymentTerms(?int $storeId = null): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . ($storeId ?? 'default');

        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            $terms = $this->serializer->unserialize($cached);
            return is_array($terms) ? $terms : [];
        }

        try {
            $terms = $this->requestFactory->create(RequestFactory::PAYMENT_TERMS, $storeId)->process();
            $terms = is_array($terms) ? $terms : [];
        } catch (Exception $e) {
            $terms = [];
        }

        $this->cache->save($this->serializer->serialize($terms), $cacheKey, [], self::CACHE_LIFETIME);

        return $terms;
    }

    /**
     * Returns the sorted, unique net terms available — optionally for one country only.
     *
     * Falls back to a static list when the API gives nothing back, so the admin
     * form never renders an empty selector.
     *
     * @param string|null $countryCode ISO-2 country code, e.g. "DE"
     * @param int|null $storeId
     * @return int[]
     */
    public function getNetTerms(?string $countryCode = null, ?int $storeId = null): array
    {
        $terms = $this->getPaymentTerms($storeId);

        $allNetTerms = $this->collectNetTerms($terms, null);
        if ($allNetTerms === []) {
            return self::FALLBACK_NET_TERMS;
        }

        if ($countryCode === null) {
            return $allNetTerms;
        }

        $forCountry = $this->collectNetTerms($terms, $countryCode);

        // The merchant has terms, just none for this country. Showing everything
        // beats an empty selector — the API stays the authority and rejects a
        // net term it does not offer.
        return $forCountry === [] ? $allNetTerms : $forCountry;
    }

    /**
     * The net term the admin form should preselect for the given country.
     *
     * 30 days is the house default, but the merchant may not have it for every
     * country, so we fall back to the available term closest to it (preferring
     * the shorter one on a tie).
     *
     * @param string|null $countryCode ISO-2 country code, e.g. "DE"
     * @param int|null $storeId
     * @return int|null
     */
    public function getDefaultNetTerm(?string $countryCode = null, ?int $storeId = null): ?int
    {
        $netTerms = $this->getNetTerms($countryCode, $storeId);

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
     * Drops the cached payment terms (e.g. after the API key changed).
     *
     * @param int|null $storeId
     * @return void
     */
    public function resetCache(?int $storeId = null): void
    {
        $this->cache->remove(self::CACHE_KEY_PREFIX . ($storeId ?? 'default'));
    }
}
