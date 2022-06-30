<?php
namespace Mondu\Mondu\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Mondu\Mondu\Gateway\Http\Client\ClientMock;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;

class ConfigProvider implements \Magento\Checkout\Model\ConfigProviderInterface
{
    const CODE = 'mondu';

    const API_URL = 'https://api.mondu.ai/api/v1';
    const SDK_URL = 'https://checkout.mondu.ai/widget.js';

    const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';
    const SANDBOX_SDK_URL = 'https://checkout.demo.mondu.ai/widget.js';

    private $urlBuilder;
    private $resourceConfig;
    private $encryptor;
    private $scopeConfig;

    public function __construct(UrlInterface $urlBuilder, ScopeConfigInterface $scopeConfig, ResourceConfig $resourceConfig, EncryptorInterface $encryptor)
    {
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->encryptor = $encryptor;
    }

    public function getApiUrl($path = null): string
    {
        $baseUrl = self::API_URL;

        if($this->scopeConfig->getValue('payment/mondu/sandbox')) {
            $baseUrl = self::SANDBOX_API_URL;
        }

        return $baseUrl . ($path ? '/'.$path : '');
    }

    public function getSdkUrl(): string
    {
        if($this->scopeConfig->getValue('payment/mondu/sandbox')) {
            return self::SANDBOX_SDK_URL;
        }
        return self::SDK_URL;
    }

    public function getMode() {
        return $this->scopeConfig->getValue('payment/mondu/sandbox') ? 'sandbox' : 'live';
    }

    public function getDebug() {
        return (bool)$this->scopeConfig->getValue('payment/mondu/debug');
    }

    public function getWebhookUrl(): string
    {
        return $this->urlBuilder->getBaseUrl().'mondu/webhooks/index';
    }

    public function getApiKey() {
        return $this->scopeConfig->getValue('payment/mondu/mondu_key');
    }

    public function isActive()
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/active');
    }


    public function getPdfUrl($orderUid, $invoiceId) {
        return $this->urlBuilder->getBaseUrl().'mondu/index/invoice?id='.$orderUid.'&r='.$invoiceId;
    }

    public function getConfig()
    {
        $string = $this->scopeConfig->getValue('payment/mondu/description');
        $pieces = explode(' ', $string);
        $last_word = array_pop($pieces);
        $description = implode(' ', $pieces);
        return [
            'payment' => [
                self::CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'transactionResults' => [
                        ClientMock::SUCCESS => __('Success'),
                        ClientMock::FAILURE => __('Fraud'),
                    ],
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $description,
                    'descriptionLink' => $last_word
                ]
            ]
        ];
    }

    public function updateWebhookSecret($webhookSecret = ""): ConfigProvider
    {
        $this->resourceConfig->saveConfig(
            'payment/mondu/'.$this->getMode(). '_webhook_secret',
            $this->encryptor->encrypt($webhookSecret)
        );

        return $this;
    }

    public function getWebhookSecret()
    {
        $val = $this->scopeConfig->getValue('payment/mondu/' . $this->getMode().'_webhook_secret');
        return $this->encryptor->decrypt($val);
    }
}
