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
    public const SEPA_CODE = 'mondusepa';
    public const INSTALLMENT_CODE = 'monduinstallment';
    public const INSTALLMENT_BY_INVOICE_CODE = 'monduinstallmentbyinvoice';

    public const API_URL = 'https://api.mondu.ai/api/v1';
    public const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';

    public const AUTHORIZATION_STATE_FLOW = 'authorization_flow';

    public const SDK_URL = 'https://checkout.mondu.ai/widget.js';
    public const SANDBOX_SDK_URL = 'https://checkout.demo.mondu.ai/widget.js';

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
     * Returns mondu.js url
     *
     * @return string
     */
    public function getSdkUrl(): string
    {
        if ($this->scopeConfig->getValue('payment/mondu/sandbox', ScopeInterface::SCOPE_STORE, $this->contextCode)) {
            return self::SANDBOX_SDK_URL;
        }
        return self::SDK_URL;
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
     * @return string
     */
    public function getWebhookUrl(): string
    {
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
            $this->scopeConfig->getValue('payment/monduinstallment/active') ||
            $this->scopeConfig->getValue('payment/monduinstallmentbyinvoice/active');
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
     * Order status for which the Cron job will process the order
     *
     * @return string
     */
    public function getCronOrderStatus(): ?string
    {
        return $this->scopeConfig->getValue('payment/mondu/cron_status');
    }

    /**
     * Is Invoice required for processing the order by the Cron job
     *
     * @return bool
     */
    public function isInvoiceRequiredCron(): bool
    {
        return (bool) $this->scopeConfig->getValue('payment/mondu/cron_require_invoice');
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
        $descriptionConfigMonduinstallmentByInvoice = $this->scopeConfig
            ->getValue('payment/monduinstallmentbyinvoice/description', ScopeInterface::SCOPE_STORE);

        $descriptionMondu = $descriptionConfigMondu ?
            __($descriptionConfigMondu) . '<br><br>' . $privacyText :
            $privacyText;
        $descriptionMondusepa = $descriptionConfigMondusepa ?
            __($descriptionConfigMondusepa) . '<br><br>' . $privacyText :
            $privacyText;
        $descriptionMonduinstallment = $descriptionConfigMonduinstallment ?
            __($descriptionConfigMonduinstallment) . '<br><br>' . $privacyText :
            $privacyText;
        $descriptionMonduinstallmentByInvoice = $descriptionConfigMonduinstallmentByInvoice ?
            __($descriptionConfigMonduinstallmentByInvoice) . '<br><br>' . $privacyText :
            $privacyText;

        return [
            'payment' => [
                self::CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondu,
                    'title' => __($this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE))
                ],
                self::SEPA_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondusepa,
                    'title' => __($this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE))
                ],
                self::INSTALLMENT_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallment,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallment/title', ScopeInterface::SCOPE_STORE))
                ],
                self::INSTALLMENT_BY_INVOICE_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallmentByInvoice,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallmentbyinvoice/title', ScopeInterface::SCOPE_STORE))
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
    public function updateWebhookSecret($webhookSecret = ""): ConfigProvider
    {
        $this->resourceConfig->saveConfig(
            'payment/mondu/'.$this->getMode(). '_webhook_secret',
            $this->encryptor->encrypt($webhookSecret)
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
        $this->configWriter->save('payment/monduinstallmentbyinvoice/order_status', $status);
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
                'payment/mondu/' . $this->getMode().'_webhook_secret'
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
