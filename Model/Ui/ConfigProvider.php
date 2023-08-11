<?php
namespace Mondu\Mondu\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Mondu\Mondu\Gateway\Http\Client\ClientMock;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\ScopeInterface;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'mondu';

    public const API_URL = 'https://api.mondu.ai/api/v1';
    public const SANDBOX_API_URL = 'http://localhost:3000/api/v1';

    public const AUTHORIZATION_STATE_FLOW = 'authorization_flow';

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var ResourceConfig
     */
    private $resourceConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var ScopeConfigInterface
     */
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

    /**
     * @param UrlInterface $urlBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param ResourceConfig $resourceConfig
     * @param EncryptorInterface $encryptor
     * @param WriterInterface $writer
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        ResourceConfig $resourceConfig,
        EncryptorInterface $encryptor,
        WriterInterface $writer,
        TypeListInterface $cacheTypeList
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->encryptor = $encryptor;
        $this->configWriter = $writer;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * Returns mondu api url
     *
     * @param string|null $path
     * @return string
     */
    public function getApiUrl($path = null): string
    {
        $baseUrl = self::API_URL;

        if ($this->scopeConfig->getValue('payment/mondu/sandbox', ScopeInterface::SCOPE_STORE, $this->contextCode)) {
            $baseUrl = self::SANDBOX_API_URL;
        }

        return $baseUrl . ($path ? '/'.$path : '');
    }

    /**
     * Get mode (sandbox or live)
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->scopeConfig
            ->getValue('payment/mondu/sandbox', ScopeInterface::SCOPE_STORE, $this->contextCode) ? 'sandbox' : 'live';
    }

    /**
     * Get Debug option
     *
     * @return bool
     */
    public function getDebug()
    {
        return (bool)$this->scopeConfig->getValue('payment/mondu/debug');
    }

    /**
     * Get Webhook url
     *
     * @param string|mixed $storeId
     * @return string
     */
    public function getWebhookUrl($storeId): string
    {
        if ($storeId) {
            return $this->urlBuilder->getBaseUrl().'mondu/webhooks/index?storeId='.$storeId;
        }
        return $this->urlBuilder->getBaseUrl().'mondu/webhooks/index';
    }

    /**
     * Get api key
     *
     * @return mixed
     */
    public function getApiKey()
    {
        return $this->scopeConfig->getValue('payment/mondu/mondu_key', ScopeInterface::SCOPE_STORE, $this->contextCode);
    }

    /**
     * True if any Mondu Payment method is active
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->scopeConfig->getValue('payment/mondu/active') ||
            $this->scopeConfig->getValue('payment/mondusepa/active') ||
            $this->scopeConfig->getValue('payment/monduinstallment/active');
    }

    /**
     * Is Cron enabled
     *
     * @return bool
     */
    public function isCronEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/cron');
    }

    /**
     * Get invoice url for order
     *
     * @param string $orderUid
     * @param string $invoiceId
     * @return string
     */
    public function getPdfUrl($orderUid, $invoiceId)
    {
        return $this->urlBuilder->getBaseUrl().'mondu/index/invoice?id='.$orderUid.'&r='.$invoiceId;
    }

    /**
     * GetConfig
     *
     * @return \array[][]
     */
    public function getConfig()
    {
        $privacyText =
            __("Information on the processing of your personal data by Mondu GmbH can be found " .
                "<a href='https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/' target='_blank'>here.</a>");

        $descriptionConfigMondu = $this->scopeConfig
            ->getValue('payment/mondu/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMondusepa = $this->scopeConfig
            ->getValue('payment/mondusepa/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMonduinstallment = $this->scopeConfig
            ->getValue('payment/monduinstallment/description', ScopeInterface::SCOPE_STORE);

        $descriptionMondu = $descriptionConfigMondu ?
            __($descriptionConfigMondu) . '<br><br>' . $privacyText :
            $privacyText;
        $descriptionMondusepa = $descriptionConfigMondusepa ?
            __($descriptionConfigMondusepa) . '<br><br>' . $privacyText :
            $privacyText;
        $descriptionMonduinstallment = $descriptionConfigMonduinstallment ?
            __($descriptionConfigMonduinstallment) . '<br><br>' . $privacyText :
            $privacyText;

        return [
            'payment' => [
                self::CODE => [
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondu,
                    'title' => __($this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE))
                ],
                'mondusepa' => [
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondusepa,
                    'title' => __($this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE))
                ],
                'monduinstallment' => [
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallment,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallment/title', ScopeInterface::SCOPE_STORE))
                ]
            ]
        ];
    }

    /**
     * Updates webhook secret
     *
     * @param string $webhookSecret
     * @param string $storeId
     * @return $this
     */
    public function updateWebhookSecret($webhookSecret = "", $storeId = 0): ConfigProvider
    {
        $this->resourceConfig->saveConfig(
            'payment/mondu/'.$this->getMode(). '_webhook_secret',
            $this->encryptor->encrypt($webhookSecret),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $this;
    }

    /**
     * Get new order status
     *
     * @return mixed
     */
    public function getNewOrderStatus()
    {
        return $this->scopeConfig->getValue('payment/mondu/order_status');
    }

    /**
     * Change new order status
     *
     * @return void
     */
    public function updateNewOrderStatus()
    {
        $status = $this->getNewOrderStatus();

        $this->configWriter->save('payment/mondusepa/order_status', $status);
        $this->configWriter->save('payment/monduinstallment/order_status', $status);
    }

    /**
     * Get webhook secret
     *
     * @return string
     */
    public function getWebhookSecret()
    {
        $val = $this->scopeConfig
            ->getValue(
                'payment/mondu/' . $this->getMode().'_webhook_secret',
                ScopeInterface::SCOPE_STORE,
                $this->contextCode
            );
        return $this->encryptor->decrypt($val);
    }

    /**
     * Get send lines (if false Mondu plugin will not send order line information to api)
     *
     * @return bool
     */
    public function sendLines()
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/send_lines');
    }

    /**
     * Get require invoice (if false Mondu plugin won't require invoice for shipping)
     *
     * @return bool
     */
    public function isInvoiceRequiredForShipping(): bool
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/require_invoice');
    }

    /**
     * Clears configuration cache
     *
     * @return void
     */
    public function clearConfigurationCache()
    {
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Sets context code ( for multiple stores )
     *
     * @param int|string $code
     * @return void
     */
    public function setContextCode($code)
    {
        $this->contextCode = $code;
    }
}
