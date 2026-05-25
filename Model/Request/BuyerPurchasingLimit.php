<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class BuyerPurchasingLimit extends CommonRequest implements RequestInterface
{
    protected $curl;

    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Fetches the purchasing limit for a buyer.
     *
     * @param array|null $params ['buyer_uuid' => string]
     * @throws LocalizedException
     * @return mixed
     */
    public function request($params = null)
    {
        $buyerUuid = $params['buyer_uuid'] ?? null;

        if (!$buyerUuid) {
            throw new LocalizedException(__('Buyer UUID is required'));
        }

        $resultJson = $this->sendRequestWithParams(
            'get',
            $this->urlBuilder->getBuyerPurchasingLimitUrl($buyerUuid)
        );

        if (!$resultJson) {
            throw new LocalizedException(__('Failed to fetch buyer purchasing limit'));
        }

        return json_decode($resultJson, true);
    }
}
