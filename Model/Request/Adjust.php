<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class Adjust extends CommonRequest implements RequestInterface
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
     * @inheritDoc
     */
    public function request($params)
    {
        $url = $this->urlBuilder->getOrderAdjustmentUrl($params['orderUid']);

        unset($params['orderUid']);
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        if (!$resultJson) {
            throw new LocalizedException(__('something went wrong'));
        }

        return json_decode($resultJson, true);
    }
}
