<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request\Webhooks;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Request\CommonRequest;
use Mondu\Mondu\Model\Request\RequestInterface;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Keys extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var string|null
     */
    protected ?string $webhookSecret = null;

    /**
     * @var int
     */
    protected int $responseStatus;

    /**
     * @var int|null
     */
    private ?int $websiteId = null;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     * @param ConfigProvider $configProvider
     * @param MonduFileLogger $monduFileLogger
     */
    public function __construct(
        Curl $curl,
        private readonly UrlBuilder $urlBuilder,
        private readonly ConfigProvider $configProvider,
        private readonly MonduFileLogger $monduFileLogger,
    ) {
        $this->curl = $curl;
    }

    /**
     * Sends request to retrieve the webhook secret key from Mondu.
     *
     * @param array|null $params
     * @return $this
     */
    protected function request($params = null): Keys
    {
        $webhookKeysUrl = $this->urlBuilder->getWebhookKeysUrl();
        $resultJson = $this->sendRequestWithParams('get', $webhookKeysUrl);
        $this->responseStatus = $this->curl->getStatus();

        $result = null;
        if ($resultJson) {
            $result = json_decode($resultJson, true);
        }
        $this->webhookSecret = $result['webhook_secret'] ?? null;

        return $this;
    }

    /**
     * Check if request was successful.
     *
     * @throws LocalizedException
     * @return $this
     */
    public function checkSuccess(): Keys
    {
        if ($this->responseStatus !== 200 && $this->responseStatus !== 201) {
            $this->monduFileLogger->error('Webhook Keys Request failed', [
                'response_status' => $this->responseStatus,
                'response_body' => $this->curl->getBody()
            ]);
            throw new LocalizedException(__(
                'Could\'t register webhooks, check to see if you entered Mondu api key correctly'
            ));
        }

        return $this;
    }
    
    /**
     * Returns the response status code.
     *
     * @return int
     */
    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    /**
     * Sets the website ID so the secret is saved at website scope.
     *
     * @param int|null $websiteId
     * @return $this
     */
    public function setWebsiteId(?int $websiteId): self
    {
        $this->websiteId = $websiteId;
        return $this;
    }

    /**
     * Updates the config with the latest webhook secret.
     *
     * @return $this
     */
    public function update(): Keys
    {
        $webhookSecret = $this->getWebhookSecret();

        if (!$webhookSecret) {
            return $this;
        }

        $this->configProvider->updateWebhookSecret($webhookSecret, $this->websiteId);

        return $this;
    }

    /**
     * Returns the webhook secret obtained from the API.
     *
     * @return string|null
     */
    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }
}
