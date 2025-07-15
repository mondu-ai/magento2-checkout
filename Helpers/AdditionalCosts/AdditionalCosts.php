<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\AdditionalCosts;

use Magento\Quote\Api\Data\CartInterface;

class AdditionalCosts implements AdditionalCostsInterface
{
    /**
     * Returns additional costs associated with quote.
     *
     * @param CartInterface $quote
     * @return int
     */
    public function getAdditionalCostsFromQuote(CartInterface $quote): int
    {
        if ($quote->getPaymentSurchargeAmount()) {
            return (int) round($quote->getPaymentSurchargeAmount(), 2) ?? 0;
        }

        return 0;
    }
}
