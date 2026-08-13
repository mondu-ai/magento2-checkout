<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment;

use Magento\Store\Model\ScopeInterface;

trait MonduCountryRestrictionTrait
{
    private const CONFIG_PATH_ALLOW_SPECIFIC = 'payment/mondu/allowspecific';
    private const CONFIG_PATH_SPECIFIC_COUNTRY = 'payment/mondu/specificcountry';

    /**
     * $country is intentionally left untyped: Magento's MethodInterface /
     * AbstractMethod::canUseForCountry($country) declares no parameter type, so
     * adding one here would break signature compatibility in the payment
     * methods that use this trait.
     *
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country): bool
    {
        $storeId = $this->getStore();

        $allowSpecific = $this->_scopeConfig
            ->getValue(self::CONFIG_PATH_ALLOW_SPECIFIC, ScopeInterface::SCOPE_STORE, $storeId);

        if ($allowSpecific == 1) {
            $availableCountries = $this->_scopeConfig
                ->getValue(self::CONFIG_PATH_SPECIFIC_COUNTRY, ScopeInterface::SCOPE_STORE, $storeId);
            $availableCountries = explode(',', (string) $availableCountries);
            if (!in_array($country, $availableCountries, true)) {
                return false;
            }
        }

        return true;
    }
}
