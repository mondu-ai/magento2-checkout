<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\Factory;
use Magento\Payment\Model\MethodInterface;
use Magento\Store\Model\StoreResolver;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Payment\Mondu;

class PaymentMethodList
{
    /**
     * @var array
     */
    private array $paymentMethods = [];

    /**
     * @param Factory $methodFactory
     * @param PaymentMethod $paymentMethodHelper
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly Factory $methodFactory,
        private readonly PaymentMethod $paymentMethodHelper,
        private readonly StoreResolver $storeResolver,
    ) {
    }

    /**
     * Returns Mondu payment method instance for the given code.
     *
     * @param string $method
     * @throws LocalizedException
     * @return MethodInterface
     */
    public function getPaymentMethod(string $method): MethodInterface
    {
        if (!isset($this->paymentMethods[$method])) {
            $this->paymentMethods[$method] = $this->methodFactory->create(Mondu::class)
                ->setCode($method);
        }

        return $this->paymentMethods[$method];
    }

    /**
     * Filters payment methods allowed for the current store.
     *
     * @param array $methods
     * @return array
     */
    public function filterMonduPaymentMethods(array $methods): array
    {
        $monduMethods = $this->paymentMethodHelper->getPayments();
        $monduAllowedMethods = $this->paymentMethodHelper->getAllowed((int) $this->storeResolver->getCurrentStoreId());
        $result = [];
        foreach ($methods as $key => $method) {
            if (in_array($key, $monduMethods, true) && !in_array($key, $monduAllowedMethods, true)) {
                continue;
            }
            $result[$key] = $method;
        }

        return $result;
    }
}
