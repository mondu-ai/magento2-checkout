<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Adjust extends CommonRequest implements RequestInterface {
    private $curl;
    private $_configProvider;
    private $_scopeConfigInterface;

    public function __construct(Curl $curl, ConfigProvider $configProvider, ScopeConfigInterface $scopeConfigInterface) {
        $this->_configProvider = $configProvider;
        $this->_scopeConfigInterface = $scopeConfigInterface;
        $this->curl = $curl;
    }

    public function process($params) {
        $api_token = $this->_scopeConfigInterface->getValue('payment/mondu/mondu_key');
        $url = $this->_configProvider->getApiUrl('orders').'/'.$params['orderUid'].'/adjust';
        $headers = $this->getHeaders($api_token);

        unset($params['orderUid']);

        $this->curl->setHeaders($headers);
        $this->curl->post($url, json_encode($params));

        $resultJson = $this->curl->getBody();

        if($resultJson) {
            $result = json_decode($resultJson, true);
        } else {
            throw new LocalizedException(__('something went wrong'));
        }

        return @$result;
    }
}