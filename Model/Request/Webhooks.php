<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
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
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(
        Curl $curl,
        private readonly ConfigProvider $configProvider,
        private readonly UrlBuilder $urlBuilder,
    ) {
        $this->curl = $curl;
    }

    /**
     * Request.
     *
     * @param array|null $params
     * @return $this
     */
    public function request($params = null): Webhooks
    {
        $this->sendRequestWithParams('post', $this->urlBuilder->getWebhooksUrl(), json_encode([
            'address' => $this->configProvider->getWebhookUrl(),
            'topic' => $this->getTopic(),
        ]));

        return $this;
    }

    /**
     * Set webhook topic.
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
     * Get webhook topic.
     *
     * @return string
     */
    private function getTopic()
    {
        return $this->topic;
    }
}
