<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Memo extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ConfigProvider
     */
    protected $configProvider;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        Curl $curl,
        ConfigProvider $configProvider
    ) {
        $this->configProvider = $configProvider;
        $this->curl = $curl;
    }

    /**
     * @inheritdoc
     */
    protected function request($params)
    {
        $url = $this->configProvider->getApiUrl('invoices').'/'.$params['invoice_uid'].'/credit_notes';

        unset($params['invoice_uid']);
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        if ($resultJson) {
            $result = json_decode($resultJson, true);
        }

        return $result ?? null;
    }
}
