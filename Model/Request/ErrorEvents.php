<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class ErrorEvents extends CommonRequest
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var bool
     */
    protected bool $sendEvents = false;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Sends plugin error events to Mondu.
     *
     * @param array $params
     * @return mixed
     */
    public function request($params)
    {
        $url = $this->urlBuilder->getPluginEventsUrl();
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        return json_decode($resultJson);
    }
}
