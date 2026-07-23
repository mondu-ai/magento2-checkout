<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment;

use Magento\Store\Model\ScopeInterface;

trait MonduCountryRestrictionTrait
{
    /**
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country): bool
    {
        $storeId = $this->getStore();

        $allowSpecific = $this->_scopeConfig
            ->getValue('payment/mondu/allowspecific', ScopeInterface::SCOPE_STORE, $storeId);

        if ($allowSpecific == 1) {
            $availableCountries = $this->_scopeConfig
                ->getValue('payment/mondu/specificcountry', ScopeInterface::SCOPE_STORE, $storeId);
            $availableCountries = explode(',', (string) $availableCountries);
            if (!in_array($country, $availableCountries, true)) {
                return false;
            }
        }

        return true;
    }
}
