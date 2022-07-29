<?php
namespace Mondu\Mondu\Model;

use Magento\Payment\Model\Method\Factory;
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
     * @param Factory $methodFactory
     */

    private $paymentMethodHelper;

    public function __construct(
        Factory $methodFactory,
        PaymentMethod $paymentMethodHelper
    ) {
        $this->methodFactory = $methodFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
    }

    public function getPaymentMethod($method)
    {
        if (!isset($this->paymentMethods[$method])) {
            $this->paymentMethods[$method] = $this->methodFactory->create(\Mondu\Mondu\Model\Payment\Mondu::class)
                ->setCode($method);
        }

        return $this->paymentMethods[$method];
    }

    public function filterMonduPaymentMethods($methods) {
        $monduMethods = $this->paymentMethodHelper->getPayments();
        $monduAllowedMethods = $this->paymentMethodHelper->getAllowed();
        $result = [];
        foreach ($methods as $key => $method) {
            if(in_array($key, $monduMethods) && !in_array($key, $monduAllowedMethods)) {
                continue;
            }
            $result[$key] = $method;
        }

        return $result;
    }
}
