<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ErrorEvents extends CommonRequest implements RequestInterface {
    protected $curl;
    protected $sendEvents = false;
    private $_configProvider;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    public function request($params) {
        $url = $this->_configProvider->getApiUrl('plugin/events');
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));
        return json_decode($resultJson);
    }
}
