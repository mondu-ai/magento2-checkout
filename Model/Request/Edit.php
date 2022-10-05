<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Edit extends CommonRequest implements RequestInterface {
    private $curl;
    private $_configProvider;
    private $_scopeConfigInterface;
    private $uid;
    public function __construct(Curl $curl, ConfigProvider $configProvider, ScopeConfigInterface $scopeConfigInterface) {
        $this->_configProvider = $configProvider;
        $this->_scopeConfigInterface = $scopeConfigInterface;
        $this->curl = $curl;
    }

    public function process($params) {
        $api_token = $this->_scopeConfigInterface->getValue('payment/mondu/mondu_key');
        if(!$this->uid) {
            throw new LocalizedException(__('No order uid provided to adjust the order'));
        }
        $url = $this->_configProvider->getApiUrl('orders').'/'.$this->uid.'/adjust';
        $headers = $this->getHeaders($api_token);


        $this->curl->setHeaders($headers);
        $this->curl->post($url, json_encode($params));

        $resultJson = $this->curl->getBody();

        if($resultJson) {
            $result = json_decode($resultJson, true);
        } else {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        return @$result;
    }

    public function setOrderUid($uid) {
        $this->uid = $uid;
        return $this;
    }
}
