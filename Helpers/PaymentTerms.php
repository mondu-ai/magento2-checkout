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
    public const FALLBACK_NET_TERMS = [7, 14, 30, 45, 60, 90];

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
        $netTerms = [];

        foreach ($this->getPaymentTerms($storeId) as $term) {
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

        return $netTerms === [] ? self::FALLBACK_NET_TERMS : $netTerms;
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
