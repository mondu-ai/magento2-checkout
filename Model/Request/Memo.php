<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Memo extends CommonRequest implements RequestInterface {
    protected $curl;
    private $_configProvider;

    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    public function request($params) {
        $url = $this->_configProvider->getApiUrl('invoices').'/'.$params['invoice_uid'].'/credit_notes';

        unset($params['invoice_uid']);
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        if($resultJson) {
            $result = json_decode($resultJson, true);
        }

        return $result;
    }
}
