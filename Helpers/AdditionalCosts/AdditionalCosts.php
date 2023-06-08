<?php

namespace Mondu\Mondu\Helpers\AdditionalCosts;

use Magento\Quote\Model\Quote;

class AdditionalCosts implements AdditionalCostsInterface
{
    /**
     * Returns additional costs associated with quote
     *
     * @param Quote $quote
     * @return int
     */
    public function getAdditionalCostsFromQuote(Quote $quote): int
    {
        if ($quote->getPaymentSurchargeAmount()) {
            return round($quote->getPaymentSurchargeAmount(), 2) ?? 0;
        }
        return 0;
    }
}
