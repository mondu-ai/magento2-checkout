<?php

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;

class MonduInstallment extends \Magento\Payment\Model\Method\AbstractMethod
{
    const PAYMENT_METHOD_MONDU_CODE = 'monduinstallment';

    protected $_code = 'monduinstallment';

    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    public function setCode($code) {
        $this->_code = $code;
        return $this;
    }

    public function isActive($storeId = null)
    {
        if ('order_place_redirect_url' === 'active') {
            return $this->getOrderPlaceRedirectUrl();
        }
        if (null === $storeId) {
            $storeId = $this->getStore();
        }

        $path = 'payment/mondu/active';
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function canUseForCountry($country) {
        $storeId = $this->getStore();

        $path = 'payment/' . 'mondu' . '/' . 'specificcountry';
        $availableCountries = $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $allowSpecific = $this->_scopeConfig->getValue('payment/mondu/allowspecific', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);

        if ($allowSpecific == 1) {
            $availableCountries = explode(',', $availableCountries);
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }
        return true;
    }
}
