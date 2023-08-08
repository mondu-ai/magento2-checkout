<?php

namespace Mondu\Mondu\Helpers\BuyerParams;

use Magento\Quote\Model\Quote;

/**
 *     Map this interface onto your custom class if you want to modify buyer params ( in di.xml )
 *     also make sure your module is loaded after Mondu
 *     <preference for="Mondu\Mondu\Helpers\BuyerParams\BuyerParamsInterface"
 *                 type="My\Module\Mondu\BuyerParamsInterface" />
 */

interface BuyerParamsInterface
{
    /**
     * Returns additional costs associated with quote
     *
     * @param array $originalData
     * @param Quote $quote
     * @return array
     */
    public function getBuyerParams(array $originalData, Quote $quote): array;
}
