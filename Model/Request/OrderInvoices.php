<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class OrderInvoices extends CommonRequest implements RequestInterface
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
    public function request($params = null)
    {
        $url = $this->configProvider->getApiUrl('orders/'. $params['order_uuid'].'/invoices');
        $resultJson = $this->sendRequestWithParams('get', $url);

        if (!$resultJson) {
            throw new LocalizedException(__('something went wrong'));
        }

        $result = json_decode($resultJson, true);
        return $result['invoices'] ?? null;
    }
}
