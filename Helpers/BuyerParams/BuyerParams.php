<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\BuyerParams;

use Magento\Quote\Api\Data\CartInterface;

class BuyerParams implements BuyerParamsInterface
{
    /**
     * Get modified buyer params if available in the billing address..
     *
     * @param array $originalData
     * @param CartInterface $quote
     * @return array
     */
    public function getBuyerParams(array $originalData, CartInterface $quote): array
    {
        if ($quote->getBillingAddress()?->getRegistrationId()) {
            $originalData['registration_id'] = $quote->getShippingAddress()->getRegistrationId();
        }

        return $originalData;
    }
}
