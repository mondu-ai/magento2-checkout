<?php

namespace Mondu\Mondu\Helpers\BuyerParams;

use Magento\Quote\Model\Quote;

class BuyerParams implements BuyerParamsInterface
{
    /**
     * Get modified buyer params
     *
     * @param array $originalData
     * @param Quote $quote
     * @return array
     */
    public function getBuyerParams(array $originalData, Quote $quote): array
    {
        if ($quote->getBillingAddress()->getRegistrationId()) {
            $originalData['registration_id'] = $quote->getShippingAddress()->getRegistrationId();
        }

        return $originalData;
    }
}
