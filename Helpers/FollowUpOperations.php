<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Gate for the calls the module makes to Mondu after an order is placed.
 *
 * A merchant who sends invoices and credit notes to Mondu from an ERP switches these calls off.
 * Magento then ships, cancels and refunds Mondu orders without sending anything to Mondu:
 * no invoice on shipment, no cancellation, no credit note, and no Mondu state check that could
 * block the change in Magento. The cron that ships orders in Mondu skips these orders too, and
 * the order view does not offer "Send credit note to Mondu".
 *
 * The Mondu mass actions in the order grid still work: the merchant picks them on purpose.
 */
class FollowUpOperations
{
    public const XML_PATH_SKIP_FOLLOW_UP_OPERATIONS = 'payment/mondu/skip_follow_up_operations';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * True when the merchant switched the follow-up calls off for this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDisabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SKIP_FOLLOW_UP_OPERATIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * True when the follow-up calls are switched off for the store of this order.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isDisabledFor(OrderInterface $order): bool
    {
        return $this->isDisabled((int) $order->getStoreId());
    }
}
