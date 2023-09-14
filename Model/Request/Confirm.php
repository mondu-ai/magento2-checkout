<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Confirm extends CommonRequest
{
    public const ORDER_STATE = ['pending', 'confirmed', 'authorized'];

    /**
     * @var Curl
     */
    protected $curl;
    /**
     * @var ConfigProvider
     */
    private $configProvider;
    /**
     * @var bool
     */
    private $validate = true;

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
        if (!$params['orderUid']) {
            throw new LocalizedException(__('Error placing an order'));
        }

        $url = $this->configProvider->getApiUrl('orders').'/'.$params['orderUid'];
        $resultJson = $this->sendRequestWithParams('get', $url);
        $result = json_decode($resultJson, true);

        if ($this->validate && !in_array($result['order']['state'] ?? null, self::ORDER_STATE)) {
            throw new LocalizedException(__('Error placing an order'));
        }

        return $result;
    }

    /**
     * SetValidate ( will check if order state is in self::ORDER_STATE )
     *
     * @param bool $validate
     * @return $this
     */
    public function setValidate($validate): Confirm
    {
        $this->validate = $validate;
        return $this;
    }
}
