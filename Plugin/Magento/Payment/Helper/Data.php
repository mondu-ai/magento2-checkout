<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Payment\Helper;

use Magento\Payment\Helper\Data as MagePaymentHelperData;
use Mondu\Mondu\Model\PaymentMethodList;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Data
{
    /**
     * @param ConfigProvider $configProvider
     * @param PaymentMethodList $paymentMethodList
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly PaymentMethodList $paymentMethodList,
    ) {
    }

    /**
     * Filters out Mondu payment methods not allowed for the current store.
     *
     * @param MagePaymentHelperData $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPaymentMethods(MagePaymentHelperData $subject, array $result): array
    {
        if (!$this->configProvider->isActive()) {
            return $result;
        }

        return $this->paymentMethodList->filterMonduPaymentMethods($result);
    }
}
