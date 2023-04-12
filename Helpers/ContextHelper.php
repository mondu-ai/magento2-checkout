<?php

namespace Mondu\Mondu\Helpers;

use Mondu\Mondu\Model\Ui\ConfigProvider;

class ContextHelper
{

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    public function __construct(
        ConfigProvider $configProvider
    ) {
        $this->configProvider = $configProvider;
    }

    /**
     * @param $order
     * @return void
     */
    public function setConfigContextForOrder($order)
    {
        $this->configProvider->setContextCode($order->getStore()->getId());
    }
}
