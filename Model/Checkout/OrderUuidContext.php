<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Checkout;

/**
 * Carries the Mondu order uuid into the order placement when there is no buyer session
 * (webhook or cron), where the checkout session does not know it.
 */
class OrderUuidContext
{
    /**
     * @var string|null
     */
    private ?string $orderUuid = null;

    /**
     * Sets the Mondu order uuid for the order being placed.
     *
     * @param string|null $orderUuid
     * @return void
     */
    public function setOrderUuid(?string $orderUuid): void
    {
        $this->orderUuid = $orderUuid;
    }

    /**
     * Returns the Mondu order uuid for the order being placed, if set.
     *
     * @return string|null
     */
    public function getOrderUuid(): ?string
    {
        return $this->orderUuid;
    }
}
