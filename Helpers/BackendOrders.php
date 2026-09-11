<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

/**
 * Gate for creating Mondu orders from the admin (POST /orders/create_async).
 *
 * Two things have to line up: the merchant switches the feature on in the module
 * config, and Mondu has the async source enabled for the account. The second one
 * has no dedicated endpoint, so it is probed once an hour and cached.
 */
class BackendOrders
{
    public const XML_PATH_BACKEND_ORDERS = 'payment/mondu/backend_orders';

    private const CACHE_KEY_PREFIX = 'mondu_async_order_support_';
    private const CACHE_LIFETIME = 3600;

    /**
     * @param CacheInterface $cache
     * @param RequestFactory $requestFactory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RequestFactory $requestFactory,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * True when the merchant switched backend orders on in the module config.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BACKEND_ORDERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * True when Mondu has the async order source switched on for this account.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAvailable(?int $storeId = null): bool
    {
        $cacheKey = self::CACHE_KEY_PREFIX . ($storeId ?? 'default');

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false && $cached !== null && $cached !== '') {
            return $cached === '1';
        }

        try {
            $available = (bool) $this->requestFactory
                ->create(RequestFactory::ASYNC_ORDER_SUPPORT, $storeId)
                ->process();
        } catch (Exception $e) {
            // A failed probe says nothing about the account — do not hide the
            // feature over a network hiccup, and do not cache the non-answer.
            return true;
        }

        $this->cache->save($available ? '1' : '0', $cacheKey, [], self::CACHE_LIFETIME);

        return $available;
    }

    /**
     * True when admin order creation should be offered at all.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->isAvailable($storeId);
    }

    /**
     * Drops the cached probe result (e.g. after the API key or the flag changed).
     *
     * @param int|null $storeId
     * @return void
     */
    public function resetCache(?int $storeId = null): void
    {
        $this->cache->remove(self::CACHE_KEY_PREFIX . ($storeId ?? 'default'));
    }
}
