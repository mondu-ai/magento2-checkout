<?php

namespace Mondu\Mondu\Model\Request\Webhooks;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Request\CommonRequest;
use Mondu\Mondu\Model\Request\RequestInterface;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Keys extends CommonRequest implements RequestInterface
{
    private $curl;
    private $_configProvider;

    public $_webhookSecret;
    public $_responseStatus;

    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->curl = $curl;
        $this->_configProvider = $configProvider;
    }

    public function process($params = null): Keys
    {
        $api_token = $this->_configProvider->getApiKey();
        $url = $this->_configProvider->getApiUrl('webhooks/keys');
        $headers = $this->getHeaders($api_token);
        $this->curl->setHeaders($headers);
        $this->curl->get($url);
        $resultJson = $this->curl->getBody();
        if($resultJson) {
            $result = json_decode($resultJson, true);
        }
        $this->_webhookSecret = @$result['webhook_secret'];
        $this->_responseStatus = $this->curl->getStatus();

        return $this;
    }

    /**
     * @throws \Exception
     */
    public function checkSuccess(): Keys
    {
        if($this->_responseStatus !== 200 && $this->_responseStatus !== 201) {
            throw new \Exception('Could\'t register webhooks, check to see if you entered Mondu api key correctly');
        }
        return $this;
    }

    public function update(): Keys
    {
        $this->_configProvider->updateWebhookSecret($this->getWebhookSecret());
        return $this;
    }

    public function getWebhookSecret()
    {
        return $this->_webhookSecret;
    }
}
