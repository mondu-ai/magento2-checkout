<?php
namespace Mondu\Mondu\Helpers;

use Mondu\Mondu\Model\PaymentMethodList;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class DataPlugin {
    /**
     * @var PaymentMethodList
     */
    private $paymentMethodList;

    /**
     * @var ConfigProvider
     */
    private $configProvider;
    public function __construct(
        PaymentMethodList $paymentMethodList,
        ConfigProvider $configProvider
    )
    {
        $this->paymentMethodList = $paymentMethodList;
        $this->configProvider = $configProvider;
    }

    public function afterGetPaymentMethods(\Magento\Payment\Helper\Data $subject, $result) {
        if(!$this->configProvider->isActive()) {
            return $result;
        }

        return $this->paymentMethodList->filterMonduPaymentMethods($result);
    }

    public function aroundGetMethodInstance(\Magento\Payment\Helper\Data $subject, callable $proceed, $code)
    {
        return $proceed($code);
//        if (false === strpos($code, 'mondu')) {
//            return $proceed($code);
//        }
//
//        return $proceed($code);
//        return $this->paymentMethodList->getPaymentMethod($code);
    }
}
