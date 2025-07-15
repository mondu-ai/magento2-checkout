<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Sales\Api\Data\OrderInterface;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ContextHelper
{
    /**
     * @param ConfigProvider $configProvider
     */
    public function __construct(private readonly ConfigProvider $configProvider)
    {
    }

    /**
     * Sets context depending on store of the order.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function setConfigContextForOrder(OrderInterface $order): void
    {
        $this->configProvider->setContextCode((int) $order->getStore()->getId());
    }
}
