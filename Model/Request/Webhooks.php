<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Webhooks extends CommonRequest implements RequestInterface
{
    /**
     * @var string
     */
    protected $topic;

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
     * Request
     *
     * @param array|null $params
     * @return $this
     */
    public function request($params = null): Webhooks
    {
        $url = $this->configProvider->getApiUrl('webhooks');

        $this->sendRequestWithParams('post', $url, json_encode([
            'address' => $this->configProvider->getWebhookUrl(),
            'topic' => $this->getTopic()
        ]));

        return $this;
    }

    /**
     * Set webhook topic
     *
     * @param string $topic
     * @return $this
     */
    public function setTopic($topic)
    {
        $this->topic = $topic;
        return $this;
    }

    /**
     * Get webhook topic
     *
     * @return string
     */
    private function getTopic()
    {
        return $this->topic;
    }
}
