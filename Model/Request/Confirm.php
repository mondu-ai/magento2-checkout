<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Confirm extends CommonRequest implements RequestInterface {
    const ORDER_STATE = ['pending', 'confirmed'];

    private $curl;
    private $_configProvider;
    private $_scopeConfigInterface;
    private $_validate = true;

    public function __construct(Curl $curl, ConfigProvider $configProvider, ScopeConfigInterface $scopeConfigInterface) {
        $this->_configProvider = $configProvider;
        $this->_scopeConfigInterface = $scopeConfigInterface;
        $this->curl = $curl;
    }

    /**
     * @throws LocalizedException
     */
    public function process($params) {
        if(!$params['orderUid']) {
            throw new LocalizedException(__('Error placing the order'));
        }
        $api_token = $this->_scopeConfigInterface->getValue('payment/mondu/mondu_key');
        $url = $this->_configProvider->getApiUrl('orders').'/'.$params['orderUid'];

        $headers = $this->getHeaders($api_token);

        $this->curl->setHeaders($headers);
        $this->curl->get($url);

        $resultJson = $this->curl->getBody();
        $result = json_decode($resultJson, true);

        if($this->_validate && !in_array($result['order']['state'], self::ORDER_STATE)) {
             throw new LocalizedException(__('Error placing the order'));
        }

        return $result;
    }

    public function setValidate($validate): Confirm
    {
        $this->_validate = $validate;
        return $this;
    }
}
