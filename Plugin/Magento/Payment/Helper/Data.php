<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Payment\Helper;

use Magento\Backend\Model\Session\Quote as QuoteSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Payment\Helper\Data as MagePaymentHelperData;
use Mondu\Mondu\Helpers\BackendOrders;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Model\PaymentMethodList;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Data
{
    /**
     * @param AppState $appState
     * @param BackendOrders $backendOrders
     * @param ConfigProvider $configProvider
     * @param PaymentMethodList $paymentMethodList
     * @param QuoteSession $quoteSession Admin order-create session (proxied, see etc/di.xml)
     */
    public function __construct(
        private readonly AppState $appState,
        private readonly BackendOrders $backendOrders,
        private readonly ConfigProvider $configProvider,
        private readonly PaymentMethodList $paymentMethodList,
        private readonly QuoteSession $quoteSession,
    ) {
    }

    /**
     * Filters out Mondu payment methods not allowed for the current store.
     * In admin context the store-based filter is skipped — the store resolver
     * returns 0 there which causes the Mondu API call to fail and would hide all
     * methods — but the methods are offered at all only when admin order
     * creation is switched on and available for the account.
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
                if (!$this->backendOrders->isActive($this->getAdminStoreId())) {
                    return $this->withoutMonduMethods($result);
                }
                unset($result['mondupaynow']);
                return $result;
            }
        } catch (\Exception $e) {
            // area not set yet — safe to continue with filter
        }

        return $this->paymentMethodList->filterMonduPaymentMethods($result);
    }

    /**
     * Drops every Mondu method from the list.
     *
     * @param array $methods
     * @return array
     */
    private function withoutMonduMethods(array $methods): array
    {
        foreach (array_keys($methods) as $code) {
            if (AsyncOrderFields::isMonduMethod((string) $code)) {
                unset($methods[$code]);
            }
        }

        return $methods;
    }

    /**
     * Store of the order being created, so the website-scoped flag is read correctly.
     *
     * @return int|null
     */
    private function getAdminStoreId(): ?int
    {
        try {
            $storeId = $this->quoteSession->getStoreId();
        } catch (\Exception $e) {
            return null;
        }

        return $storeId !== null ? (int) $storeId : null;
    }
}
