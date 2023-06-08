<?php

namespace Mondu\Mondu\Model\Request\Webhooks;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
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
     * @var ConfigProvider
     */
    protected $configProvider;

    /**
     * @var int
     */
    protected $storeId = 0;

    /**
     * @var string
     */
    protected $webhookSecret;

    /**
     * @var int
     */
    protected $responseStatus;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     */
    public function __construct(Curl $curl, ConfigProvider $configProvider)
    {
        $this->curl = $curl;
        $this->configProvider = $configProvider;
    }

    /**
     * Request
     *
     * @param array|null $params
     * @return $this
     */
    protected function request($params = null): Keys
    {
        $url = $this->configProvider->getApiUrl('webhooks/keys');
        $resultJson = $this->sendRequestWithParams('get', $url);

        if ($resultJson) {
            $result = json_decode($resultJson, true);
        }

        $this->webhookSecret = $result['webhook_secret'] ?? null;
        $this->responseStatus = $this->curl->getStatus();

        return $this;
    }

    /**
     * Check if request was successful
     *
     * @return $this
     * @throws LocalizedException
     */
    public function checkSuccess(): Keys
    {
        if ($this->responseStatus !== 200 && $this->responseStatus !== 201) {
            throw new LocalizedException(__(
                'Could\'t register webhooks, check to see if you entered Mondu api key correctly'
            ));
        }
        return $this;
    }

    /**
     * Update
     *
     * @return $this
     */
    public function update(): Keys
    {
        $this->configProvider->updateWebhookSecret($this->getWebhookSecret(), $this->storeId);
        return $this;
    }

    /**
     * Get Webhook Secret
     *
     * @return string
     */
    public function getWebhookSecret()
    {
        return $this->webhookSecret;
    }

    /**
     * Set store
     *
     * @param int $storeId
     * @return $this
     */
    public function setStore($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }
}
