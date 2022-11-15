<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Confirm extends CommonRequest implements RequestInterface {
    const ORDER_STATE = ['pending', 'confirmed'];

    protected $curl;
    private $_configProvider;
    private $_validate = true;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    /**
     * @throws LocalizedException
     */
    public function request($params) {
        if(!$params['orderUid']) {
            throw new LocalizedException(__('Error placing an order'));
        }
        $url = $this->_configProvider->getApiUrl('orders').'/'.$params['orderUid'];

        $this->curl->get($url);

        $resultJson = $this->curl->getBody();
        $result = json_decode($resultJson, true);

        if($this->_validate && !in_array($result['order']['state'], self::ORDER_STATE)) {
             throw new LocalizedException(__('Error placing an order'));
        }

        return $result;
    }

    public function setValidate($validate): Confirm
    {
        $this->_validate = $validate;
        return $this;
    }
}
