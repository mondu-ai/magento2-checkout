<?php

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;

class MonduInstallmentByInvoice extends \Magento\Payment\Model\Method\AbstractMethod
{
    public const PAYMENT_METHOD_MONDU_CODE = 'monduinstallmentbyinvoice';

    /**
     * @var string
     */
    protected $_code = 'monduinstallmentbyinvoice';

    /**
     * Authorize
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this|MonduInstallmentByInvoice
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * SetCode
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->_code = $code;
        return $this;
    }

    /**
     * CanUseForCountry
     *
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country)
    {
        $storeId = $this->getStore();

        $path = 'payment/' . 'mondu' . '/' . 'specificcountry';
        $availableCountries = $this->_scopeConfig
            ->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $allowSpecific = $this->_scopeConfig
            ->getValue('payment/mondu/allowspecific', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);

        if ($allowSpecific == 1) {
            $availableCountries = explode(',', $availableCountries);
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }
        return true;
    }
}
