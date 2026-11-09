<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Tells the order flows apart at placement.
 *
 * A new order placed in the admin goes to Mondu through create_async
 * (CreateAsyncOrder). Every other placement, including an admin edit of an
 * existing order, goes through the checkout observers (CreateOrder,
 * AfterPlaceOrder), which adjust or cancel the Mondu order the edit replaces.
 */
class AdminOrder
{
    /**
     * @param AppState $appState
     */
    public function __construct(private readonly AppState $appState)
    {
    }

    /**
     * True for an order that replaces another one, as an admin edit does.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isEdit(OrderInterface $order): bool
    {
        return (bool) ($order->getRelationParentRealId() || $order->getRelationParentId());
    }

    /**
     * True for a new order placed in the admin, which Mondu gets through create_async.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isNewAdminOrder(OrderInterface $order): bool
    {
        return $this->isAdminArea() && !$this->isEdit($order);
    }

    /**
     * True when the current request runs in the admin area.
     *
     * @return bool
     */
    public function isAdminArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (Exception $e) {
            // No area set yet, which is never the admin.
            return false;
        }
    }
}
