<?php
namespace Mondu\Mondu\Helpers;

use Magento\Payment\Helper\Data;
use Mondu\Mondu\Model\PaymentMethodList;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class DataPlugin
{
    /**
     * @var PaymentMethodList
     */
    private $paymentMethodList;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param PaymentMethodList $paymentMethodList
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        PaymentMethodList $paymentMethodList,
        ConfigProvider $configProvider
    ) {
        $this->paymentMethodList = $paymentMethodList;
        $this->configProvider = $configProvider;
    }

    /**
     * Filters Mondu payment methods
     *
     * @param Data $subject
     * @param array $result
     * @return array
     */
    public function afterGetPaymentMethods(Data $subject, $result)
    {
        if (!$this->configProvider->isActive()) {
            return $result;
        }

        return $this->paymentMethodList->filterMonduPaymentMethods($result);
    }

    /**
     * AroundGetMethodInstance
     *
     * @param Data $subject
     * @param callable $proceed
     * @param mixed $code
     * @return mixed
     */
    public function aroundGetMethodInstance(Data $subject, callable $proceed, $code)
    {
        return $proceed($code);
    }
}
