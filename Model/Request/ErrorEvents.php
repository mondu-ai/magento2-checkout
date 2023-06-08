<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ErrorEvents extends CommonRequest
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var bool
     */
    protected $sendEvents = false;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(Curl $curl, ConfigProvider $configProvider)
    {
        $this->configProvider = $configProvider;
        $this->curl = $curl;
    }

    /**
     * Request
     *
     * @param array $params
     * @return mixed
     */
    public function request($params)
    {
        $url = $this->configProvider->getApiUrl('plugin/events');

        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        return json_decode($resultJson);
    }
}
