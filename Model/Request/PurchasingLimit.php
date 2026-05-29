<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class PurchasingLimit extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * @param array|null $params
     * @throws LocalizedException
     * @return array
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
            throw new LocalizedException(__('Purchasing limit request failed'));
        }

        return json_decode($resultJson, true);
    }
}
