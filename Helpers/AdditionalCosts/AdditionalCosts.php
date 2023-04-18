<?php

namespace Mondu\Mondu\Helpers\AdditionalCosts;

use Magento\Quote\Model\Quote;

class AdditionalCosts implements AdditionalCostsInterface
{
    public function getAdditionalCostsFromQuote(Quote $quote): int
    {
        return round($quote->getPaymentSurchargeAmount(), 2) ?? 0;
    }
}
