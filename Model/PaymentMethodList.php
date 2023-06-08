<?php
namespace Mondu\Mondu\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\Factory;
use Magento\Store\Model\StoreResolver;
use Mondu\Mondu\Helpers\PaymentMethod;

class PaymentMethodList
{
    /**
     * Factory for payment method models
     *
     * @var Factory
     */
    private $methodFactory;

    /**
     * @var \Mondu\Mondu\Model\Payment\Mondu[]
     */
    private $paymentMethods = [];

    /**
     * @var PaymentMethod
     */
    private $paymentMethodHelper;

    /**
     * @var StoreResolver
     */
    private $storeResolver;

    /**
     * @param Factory $methodFactory
     * @param PaymentMethod $paymentMethodHelper
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        Factory $methodFactory,
        PaymentMethod $paymentMethodHelper,
        StoreResolver $storeResolver
    ) {
        $this->methodFactory = $methodFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->storeResolver = $storeResolver;
    }

    /**
     * GetPaymentMethod
     *
     * @param string $method
     * @return Payment\Mondu
     * @throws LocalizedException
     */
    public function getPaymentMethod($method)
    {
        if (!isset($this->paymentMethods[$method])) {
            $this->paymentMethods[$method] = $this->methodFactory->create(\Mondu\Mondu\Model\Payment\Mondu::class)
                ->setCode($method);
        }

        return $this->paymentMethods[$method];
    }

    /**
     * FilterMonduPaymentMethods
     *
     * @param string $methods
     * @return array
     */
    public function filterMonduPaymentMethods($methods)
    {
        $monduMethods = $this->paymentMethodHelper->getPayments();
        $monduAllowedMethods = $this->paymentMethodHelper->getAllowed($this->storeResolver->getCurrentStoreId());
        $result = [];
        foreach ($methods as $key => $method) {
            if (in_array($key, $monduMethods) && !in_array($key, $monduAllowedMethods)) {
                continue;
            }
            $result[$key] = $method;
        }

        return $result;
    }
}
