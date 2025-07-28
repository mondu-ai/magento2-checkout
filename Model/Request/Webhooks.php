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
    protected string $topic;

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
     * Sends a webhook registration request to Mondu.
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
     * Sets the topic to be used for webhook registration.
     *
     * @param string $topic
     * @return $this
     */
    public function setTopic(string $topic): self
    {
        $this->topic = $topic;
        return $this;
    }

    /**
     * Returns the current webhook topic.
     *
     * @return string
     */
    private function getTopic(): string
    {
        return $this->topic;
    }
}
