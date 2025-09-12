<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
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
     * @var int|null
     */
    private ?int $storeId = null;

    /**
     * @param Curl            $curl
     * @param ConfigProvider  $configProvider
     * @param UrlBuilder      $urlBuilder
     * @param MonduFileLogger $monduFileLogger
     */
    public function __construct(
        Curl $curl,
        private readonly ConfigProvider $configProvider,
        private readonly UrlBuilder $urlBuilder,
        private readonly MonduFileLogger $monduFileLogger,
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
        if ($this->storeId !== null) {
            $this->configProvider->setContextCode($this->storeId);
        }

        $this->sendRequestWithParams('post', $this->urlBuilder->getWebhooksUrl(), json_encode([
            'address' => $this->configProvider->getWebhookUrl($this->storeId),
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
     * Sets the store ID for multistore support.
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        $this->storeId = $storeId;
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
