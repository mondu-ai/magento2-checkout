<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Cancel extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

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
    public function request($params)
    {
        $url = $this->configProvider->getApiUrl('orders').'/'.$params['orderUid'].'/cancel';

        unset($params['orderUid']);
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode([]));

        if ($resultJson) {
            $result = json_decode($resultJson, true);
        }

        return $result ?? null;
    }
}
