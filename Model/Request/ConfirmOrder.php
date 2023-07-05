<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ConfirmOrder extends CommonRequest implements RequestInterface
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
        $this->curl = $curl;
        $this->configProvider = $configProvider;
    }

    /**
     * @inheritDoc
     */
    protected function request($params)
    {
        $url = $this->configProvider->getApiUrl('orders') . '/' . $params['orderUid'] . '/confirm';

        $resultJson = $this->sendRequestWithParams(
            'post',
            $url,
            json_encode(['external_reference_id' => $params['referenceId']])
        );

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || isset($result['error'])) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        return $result;
    }
}
