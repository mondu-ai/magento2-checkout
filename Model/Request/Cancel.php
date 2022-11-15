<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Cancel extends CommonRequest implements RequestInterface {
    protected $curl;
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
        $url = $this->_configProvider->getApiUrl('orders').'/'.$params['orderUid'].'/cancel';

        unset($params['orderUid']);

        $this->curl->post($url, json_encode([]));

        $resultJson = $this->curl->getBody();

        if($resultJson) {
            $result = json_decode($resultJson, true);
        }

        return $result ?? null;
    }
}
