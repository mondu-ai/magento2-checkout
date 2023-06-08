<?php

namespace Mondu\Mondu\Helpers;

use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ContextHelper
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        ConfigProvider $configProvider
    ) {
        $this->configProvider = $configProvider;
    }

    /**
     * Sets context depending on store of the order
     *
     * @param Order $order
     * @return void
     */
    public function setConfigContextForOrder($order)
    {
        $this->configProvider->setContextCode($order->getStore()->getId());
    }
}
