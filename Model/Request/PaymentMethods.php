<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class PaymentMethods extends CommonRequest implements RequestInterface {
    /**
     * @var Curl
     */
    protected $curl;
    /**
     * @var ConfigProvider
     */
    private $_configProvider;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    public function request($_params = null) {
        $url = $this->_configProvider->getApiUrl('payment_methods');
        $this->curl->get($url);

        $resultJson = $this->curl->getBody();

        if($resultJson) {
            $result = json_decode($resultJson, true);
            $result = @$result['payment_methods'];
        } else {
            throw new LocalizedException(__('something went wrong'));
        }

        return @$result;
    }
}
