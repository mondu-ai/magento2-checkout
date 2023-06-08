<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Edit extends CommonRequest
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
     * @var string
     */
    protected $uid;

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
     * Request
     *
     * @param array $params
     * @return mixed
     * @throws LocalizedException
     */
    protected function request($params)
    {
        if (!$this->uid) {
            throw new LocalizedException(__('No order uid provided to adjust the order'));
        }

        $url = $this->configProvider->getApiUrl('orders') . '/' . $this->uid . '/adjust';
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        return json_decode($resultJson, true);
    }

    /**
     * Sets order uid ( used before sending the request )
     *
     * @param string $uid
     * @return $this
     */
    public function setOrderUid($uid)
    {
        $this->uid = $uid;
        return $this;
    }
}
