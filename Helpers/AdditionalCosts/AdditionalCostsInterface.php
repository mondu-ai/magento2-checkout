<?php

namespace Mondu\Mondu\Helpers\AdditionalCosts;

use Magento\Quote\Model\Quote;

/**
 *     Map this interface onto your custom class if you have additional costs attached to payment methods ( in di.xml )
 *     also make sure your module is loaded after Mondu
 *     <preference for="Mondu\Mondu\Helpers\AdditionalCosts\AdditionalCostsInterface"
 *                 type="My\Module\Mondu\AdditionalCosts" />
 */

interface AdditionalCostsInterface
{
    /**
     * Returns additional costs associated with quote
     *
     * @param Quote $quote
     * @return int
     */
    public function getAdditionalCostsFromQuote(Quote $quote): int;
}
