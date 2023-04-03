<?php
namespace Mondu\Mondu\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Mondu\Mondu\Gateway\Http\Client\ClientMock;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\ScopeInterface;

class ConfigProvider implements \Magento\Checkout\Model\ConfigProviderInterface
{
    const CODE = 'mondu';

    const API_URL = 'https://api.mondu.ai/api/v1';
    const SDK_URL = 'https://checkout.mondu.ai/widget.js';

    const SANDBOX_API_URL = 'http://localhost:3000/api/v1';
    const SANDBOX_SDK_URL = 'http://checkout-sandbox.mondu.local/widget.js';

    private $urlBuilder;
    private $resourceConfig;
    private $encryptor;
    private $scopeConfig;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var string|null
     */
    private $contextCode = null;

    public function __construct(
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        ResourceConfig $resourceConfig,
        EncryptorInterface $encryptor,
        WriterInterface $writer,
        TypeListInterface $cacheTypeList
    )
    {
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->encryptor = $encryptor;
        $this->configWriter = $writer;
        $this->cacheTypeList = $cacheTypeList;
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
        return $this->scopeConfig->getValue('payment/mondu/mondu_key', \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE, $this->contextCode);
    }

    public function isActive()
    {
        return $this->scopeConfig->getValue('payment/mondu/active') || $this->scopeConfig->getValue('payment/mondusepa/active') || $this->scopeConfig->getValue('payment/monduinstallment/active');
    }

    public function isCronEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/cron');
    }

    public function getPdfUrl($orderUid, $invoiceId) {
        return $this->urlBuilder->getBaseUrl().'mondu/index/invoice?id='.$orderUid.'&r='.$invoiceId;
    }

    public function getConfig()
    {
        $privacyText = __("Information on the processing of your personal data by Mondu GmbH can be found <a href='https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/' target='_blank'>here.</a>");
        $descriptionConfigMondu = $this->scopeConfig->getValue('payment/mondu/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMondusepa = $this->scopeConfig->getValue('payment/mondusepa/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMonduinstallment = $this->scopeConfig->getValue('payment/monduinstallment/description', ScopeInterface::SCOPE_STORE);
        
        $descriptionMondu = $descriptionConfigMondu ? __($descriptionConfigMondu) . '<br><br>' . $privacyText : $privacyText;
        $descriptionMondusepa = $descriptionConfigMondusepa ? __($descriptionConfigMondusepa) . '<br><br>' . $privacyText : $privacyText;
        $descriptionMonduinstallment = $descriptionConfigMonduinstallment ? __($descriptionConfigMonduinstallment) . '<br><br>' . $privacyText : $privacyText;
        
        return [
            'payment' => [
                self::CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'transactionResults' => [
                        ClientMock::SUCCESS => __('Success'),
                        ClientMock::FAILURE => __('Fraud'),
                    ],
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondu,
                    'title' => __($this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE))
                ],
                'mondusepa' => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondusepa,
                    'title' => __($this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE))
                ],
                'monduinstallment' => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallment,
                    'title' => __($this->scopeConfig->getValue('payment/monduinstallment/title', ScopeInterface::SCOPE_STORE))
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

    public function getNewOrderStatus()
    {
        return $this->scopeConfig->getValue('payment/mondu/order_status');
    }

    public function updateNewOrderStatus()
    {
        $status = $this->getNewOrderStatus();

        $this->configWriter->save('payment/mondusepa/order_status', $status);
        $this->configWriter->save('payment/monduinstallment/order_status', $status);
    }

    public function getWebhookSecret()
    {
        $val = $this->scopeConfig->getValue('payment/mondu/' . $this->getMode().'_webhook_secret');
        return $this->encryptor->decrypt($val);
    }

    public function sendLines()
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/send_lines');
    }

    public function isInvoiceRequiredForShipping()
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/require_invoice');
    }

    public function clearConfigurationCache()
    {
        $this->cacheTypeList->cleanType('config');
    }

    public function setContextCode($code) {
        $this->contextCode = $code;
    }
}
