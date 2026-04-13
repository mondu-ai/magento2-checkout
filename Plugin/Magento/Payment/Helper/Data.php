<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Payment\Helper;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Payment\Helper\Data as MagePaymentHelperData;
use Mondu\Mondu\Model\PaymentMethodList;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Data
{
    /**
     * @param AppState $appState
     * @param ConfigProvider $configProvider
     * @param PaymentMethodList $paymentMethodList
     */
    public function __construct(
        private readonly AppState $appState,
        private readonly ConfigProvider $configProvider,
        private readonly PaymentMethodList $paymentMethodList,
    ) {
    }

    /**
     * Filters out Mondu payment methods not allowed for the current store.
     * In admin context the filter is skipped — the store resolver returns 0
     * there which causes the Mondu API call to fail and would hide all methods.
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

        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                unset($result['mondupaynow']);
                return $result;
            }
        } catch (\Exception $e) {
            // area not set yet — safe to continue with filter
        }

        return $this->paymentMethodList->filterMonduPaymentMethods($result);
    }
}
