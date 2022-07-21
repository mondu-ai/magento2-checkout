<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class PaymentMethods extends CommonRequest implements RequestInterface {
    /**
     * @var Curl
     */
    private $curl;
    /**
     * @var ConfigProvider
     */
    private $_configProvider;
    /**
     * @var ScopeConfigInterface
     */
    private $_scopeConfigInterface;

    public function __construct(Curl $curl, ConfigProvider $configProvider, ScopeConfigInterface $scopeConfigInterface) {
        $this->_configProvider = $configProvider;
        $this->_scopeConfigInterface = $scopeConfigInterface;
        $this->curl = $curl;
    }

    public function process($_params = null) {
        $api_token = $this->_scopeConfigInterface->getValue('payment/mondu/mondu_key');
        $url = $this->_configProvider->getApiUrl('payment_methods');
        $headers = $this->getHeaders($api_token);


        $this->curl->setHeaders($headers);
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
