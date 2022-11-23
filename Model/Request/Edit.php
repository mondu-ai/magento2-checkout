<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Edit extends CommonRequest implements RequestInterface {
    protected $curl;
    private $_configProvider;
    private $uid;
    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    public function request($params) {
        if(!$this->uid) {
            throw new LocalizedException(__('No order uid provided to adjust the order'));
        }
        $url = $this->_configProvider->getApiUrl('orders').'/'.$this->uid.'/adjust';

        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

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
