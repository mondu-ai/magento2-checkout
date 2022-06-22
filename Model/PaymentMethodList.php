<?php
namespace Mondu\Mondu\Model;

use Magento\Payment\Model\Method\Factory;

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
     * @param Factory                  $methodFactory
     */
    public function __construct(
        Factory $methodFactory
    ) {
        $this->methodFactory = $methodFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentMethod($method)
    {
        if (!isset($this->paymentMethods[$method])) {
            $this->paymentMethods[$method] = $this->methodFactory->create(\Mondu\Mondu\Model\Payment\Mondu::class)
                ->setCode($method);
        }
        return $this->paymentMethods[$method];
    }
}
