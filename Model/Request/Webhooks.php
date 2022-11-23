<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Webhooks extends CommonRequest implements RequestInterface
{
    public $_topic;
    protected $curl;
    private $_configProvider;

    public function __construct(Curl $curl, ConfigProvider $configProvider) {
        $this->curl = $curl;
        $this->_configProvider = $configProvider;
    }

    public function request($params = null): Webhooks
    {
        $url = $this->_configProvider->getApiUrl('webhooks');

        $this->sendRequestWithParams('post', $url, json_encode([
            'address' => $this->_configProvider->getWebhookUrl(),
            'topic' => $this->getTopic()
        ]));

        return $this;
    }

    public function setTopic($topic) {
        $this->_topic = $topic;
        return $this;
    }

    private function getTopic() {
        return $this->_topic;
    }
}
