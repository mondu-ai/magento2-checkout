<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\AdditionalCosts;

use Magento\Quote\Api\Data\CartInterface;

/**
 *     Map this interface onto your custom class if you have additional costs attached to payment methods ( in di.xml )
 *     also make sure your module is loaded after Mondu
 *     <preference for="Mondu\Mondu\Helpers\AdditionalCosts\AdditionalCostsInterface"
 *                 type="My\Module\Mondu\AdditionalCosts" />
 */
interface AdditionalCostsInterface
{
    /**
     * Returns additional costs associated with quote.
     *
     * @param CartInterface $quote
     * @return int
     */
    public function getAdditionalCostsFromQuote(CartInterface $quote): int;
}
