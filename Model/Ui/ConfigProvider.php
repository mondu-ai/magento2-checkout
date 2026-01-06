<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'mondu';
    public const SEPA_CODE = 'mondusepa';
    public const INSTALLMENT_CODE = 'monduinstallment';
    public const INSTALLMENT_BY_INVOICE_CODE = 'monduinstallmentbyinvoice';
    public const PAY_NOW_CODE = 'mondupaynow';

    public const API_URL = 'https://api.mondu.ai/api/v1';
    public const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';
    public const SDK_URL = 'https://checkout.mondu.ai/widget.js';
    public const SANDBOX_SDK_URL = 'https://checkout.demo.mondu.ai/widget.js';

    public const AUTHORIZATION_STATE_FLOW = 'authorization_flow';

    /**
     * @var int|null
     */
    private ?int $contextCode = null;

    /**
     * Payment method to image mapping
     */
    private const PAYMENT_METHOD_IMAGES = [
        self::CODE => 'invoice_white_rectangle.png',
        self::SEPA_CODE => 'sepa_white_rectangle.png',
        self::INSTALLMENT_CODE => 'installments_white_rectangle.png',
        self::INSTALLMENT_BY_INVOICE_CODE => 'installments_white_rectangle.png',
        self::PAY_NOW_CODE => 'instant_pay_white_rectangle.png',
        self::PAYMENT_CODE => 'mondu_payment_white_rectangle.png',
    ];

    /**
     * Supported locales for images
     */
    private const SUPPORTED_IMAGE_LOCALES = ['de', 'en', 'nl'];

    /**
     * @param EncryptorInterface $encryptor
     * @param ResourceConfig $resourceConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param TypeListInterface $cacheTypeList
     * @param UrlInterface $urlBuilder
     * @param WriterInterface $writer
     * @param StoreManagerInterface $storeManager
     * @param ResolverInterface $localeResolver
     * @param AssetRepository $assetRepository
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly ResourceConfig $resourceConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly UrlInterface $urlBuilder,
        private readonly WriterInterface $writer,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * Returns the widget SDK URL based on the current mode.
     *
     * @return string
     */
    public function getSdkUrl(): string
    {
        return $this->isSandboxModeEnabled() ? self::SANDBOX_SDK_URL : self::SDK_URL;
    }

    /**
     * Returns the current mode (sandbox or live).
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->isSandboxModeEnabled() ? 'sandbox' : 'live';
    }

    /**
     * Checks if sandbox mode is enabled.
     *
     * @return bool
     */
    public function isSandboxModeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('payment/mondu/sandbox', ScopeInterface::SCOPE_STORE, $this->contextCode);
    }

    /**
     * Checks if debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugModeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('payment/mondu/debug', ScopeInterface::SCOPE_STORE, $this->contextCode);
    }

    /**
     * Returns the webhook endpoint URL.
     *
     * @param int|null $storeId Store ID for multistore support (optional, uses contextCode if not provided)
     * @return string
     */
    public function getWebhookUrl(?int $storeId = null): string
    {
        $effectiveStoreId = $storeId ?? $this->contextCode;
        
        if ($effectiveStoreId !== null) {
            try {
                $store = $this->storeManager->getStore($effectiveStoreId);
                return $store->getBaseUrl(UrlInterface::URL_TYPE_WEB) . 'mondu/webhooks/index';
            } catch (NoSuchEntityException $e) {
                return $this->urlBuilder->getBaseUrl() . 'mondu/webhooks/index';
            }
        }
        
        return $this->urlBuilder->getBaseUrl() . 'mondu/webhooks/index';
    }

    /**
     * Returns the configured API key.
     *
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        return $this->scopeConfig->getValue('payment/mondu/mondu_key', ScopeInterface::SCOPE_STORE, $this->contextCode);
    }

    /**
     * Checks if any Mondu payment method is enabled.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->scopeConfig->isSetFlag('payment/mondu/active', ScopeInterface::SCOPE_STORE, $this->contextCode) ||
            $this->scopeConfig->isSetFlag(
                'payment/mondusepa/active',
                ScopeInterface::SCOPE_STORE,
                $this->contextCode
            ) ||
            $this->scopeConfig->isSetFlag(
                'payment/monduinstallment/active',
                ScopeInterface::SCOPE_STORE,
                $this->contextCode
            ) ||
            $this->scopeConfig->isSetFlag(
                'payment/monduinstallmentbyinvoice/active',
                ScopeInterface::SCOPE_STORE,
                $this->contextCode
            ) ||
            $this->scopeConfig->isSetFlag(
                'payment/mondupaynow/active',
                ScopeInterface::SCOPE_STORE,
                $this->contextCode
            );
    }

    /**
     * Checks if Cron processing is enabled.
     *
     * @return bool
     */
    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('payment/mondu/cron', ScopeInterface::SCOPE_STORE, $this->contextCode);
    }

    /**
     * Returns the order status used by Cron for filtering.
     *
     * @return string|null
     */
    public function getCronOrderStatus(): ?string
    {
        return $this->scopeConfig->getValue(
            'payment/mondu/cron_status',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );
    }

    /**
     * Checks whether an invoice is required for Cron processing.
     *
     * @return bool
     */
    public function isInvoiceRequiredCron(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/mondu/cron_require_invoice',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );
    }

    /**
     * Returns the PDF invoice download URL.
     *
     * @param string $orderUid
     * @param string $invoiceId
     * @return string
     */
    public function getPdfUrl(string $orderUid, string $invoiceId): string
    {
        return $this->urlBuilder->getBaseUrl() . 'mondu/index/invoice?id=' . $orderUid . '&r=' . $invoiceId;
    }

    /**
     * GetConfig.
     *
     * @return array
     */
    public function getConfig(): array
    {
        $privacyText
            = __('Information on the processing of your personal data by Mondu GmbH can be found '
                . "<a href='https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/' target='_blank'>here.</a>");

        $descriptionConfigMondu = $this->scopeConfig
            ->getValue('payment/mondu/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMondusepa = $this->scopeConfig
            ->getValue('payment/mondusepa/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMonduinstallment = $this->scopeConfig
            ->getValue('payment/monduinstallment/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMonduinstallmentByInvoice = $this->scopeConfig
            ->getValue('payment/monduinstallmentbyinvoice/description', ScopeInterface::SCOPE_STORE);
        $descriptionConfigMonduPayNow = $this->scopeConfig
            ->getValue('payment/mondupaynow/description', ScopeInterface::SCOPE_STORE);

        $descriptionMondu = $descriptionConfigMondu
            ? __($descriptionConfigMondu) . '<br><br>' . $privacyText
            : $privacyText;
        $descriptionMondusepa = $descriptionConfigMondusepa
            ? __($descriptionConfigMondusepa) . '<br><br>' . $privacyText
            : $privacyText;
        $descriptionMonduinstallment = $descriptionConfigMonduinstallment
            ? __($descriptionConfigMonduinstallment) . '<br><br>' . $privacyText
            : $privacyText;
        $descriptionMonduinstallmentByInvoice = $descriptionConfigMonduinstallmentByInvoice
            ? __($descriptionConfigMonduinstallmentByInvoice) . '<br><br>' . $privacyText
            : $privacyText;
        $descriptionMonduPayNow = $descriptionConfigMonduPayNow
            ? __($descriptionConfigMonduPayNow) . '<br><br>' . $privacyText
            : $privacyText;

        return [
            'payment' => [
                self::CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondu,
                    'title' => __($this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE)),
                    'imageUrl' => $this->getPaymentMethodImageUrl(self::CODE),
                ],
                self::SEPA_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondusepa,
                    'title' => __($this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE)),
                    'imageUrl' => $this->getPaymentMethodImageUrl(self::SEPA_CODE),
                ],
                self::INSTALLMENT_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallment,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallment/title', ScopeInterface::SCOPE_STORE)),
                    'imageUrl' => $this->getPaymentMethodImageUrl(self::INSTALLMENT_CODE),
                ],
                self::INSTALLMENT_BY_INVOICE_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallmentByInvoice,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallmentbyinvoice/title', ScopeInterface::SCOPE_STORE)),
                    'imageUrl' => $this->getPaymentMethodImageUrl(self::INSTALLMENT_BY_INVOICE_CODE),
                ],
                self::PAY_NOW_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduPayNow,
                    'title' => __($this->scopeConfig
                                      ->getValue('payment/mondupaynow/title', ScopeInterface::SCOPE_STORE)),
                    'imageUrl' => $this->getPaymentMethodImageUrl(self::PAY_NOW_CODE),
                ],
            ],
        ];
    }

    /**
     * Updates webhook secret.
     *
     * @param string $webhookSecret
     * @param int|null $storeId Store ID for multistore support
     * @return $this
     */
    public function updateWebhookSecret($webhookSecret = "", ?int $storeId = null): self
    {
        $scope = $storeId !== null ? ScopeInterface::SCOPE_STORES : ScopeConfigInterface::SCOPE_TYPE_DEFAULT;

        $this->resourceConfig->saveConfig(
            'payment/mondu/' . $this->getMode() . '_webhook_secret',
            $this->encryptor->encrypt($webhookSecret),
            $scope,
            $storeId
        );

        return $this;
    }

    /**
     * Returns the configured order status for new orders.
     *
     * @return string
     */
    public function getNewOrderStatus(): string
    {
        return $this->scopeConfig->getValue(
            'payment/mondu/order_status',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );
    }

    /**
     * Updates the order status for all Mondu payment methods.
     *
     * @return void
     */
    public function updateNewOrderStatus(): void
    {
        $status = $this->getNewOrderStatus();

        $this->writer->save('payment/mondusepa/order_status', $status);
        $this->writer->save('payment/monduinstallment/order_status', $status);
        $this->writer->save('payment/monduinstallmentbyinvoice/order_status', $status);
        $this->writer->save('payment/mondupaynow/order_status', $status);
    }

    /**
     * Returns the decrypted webhook secret.
     *
     * @return string
     */
    public function getWebhookSecret(): string
    {
        $val = $this->scopeConfig->getValue(
            'payment/mondu/' . $this->getMode() . '_webhook_secret',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );

        return $this->encryptor->decrypt($val);
    }

    /**
     * Get send lines (if false Mondu plugin will not send order line information to api).
     *
     * @return bool
     */
    public function sendLines(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/mondu/send_lines',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );
    }

    /**
     * Get require invoice (if false Mondu plugin won't require invoice for shipping).
     *
     * @return bool
     */
    public function isInvoiceRequiredForShipping(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/mondu/require_invoice',
            ScopeInterface::SCOPE_STORE,
            $this->contextCode
        );
    }

    /**
     * Clears configuration cache.
     *
     * @return void
     */
    public function clearConfigurationCache(): void
    {
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Returns the image URL for the given payment method code.
     *
     * @param string $paymentMethodCode
     * @return string
     */
    public function getPaymentMethodImageUrl(string $paymentMethodCode): string
    {
        if (!isset(self::PAYMENT_METHOD_IMAGES[$paymentMethodCode])) {
            return '';
        }

        $locale = $this->getImageLocale();
        $imageName = self::PAYMENT_METHOD_IMAGES[$paymentMethodCode];

        return $this->assetRepository->getUrl(
            'Mondu_Mondu::images/' . $locale . '/' . $imageName
        );
    }

    /**
     * Returns the locale code for images (de, en, nl).
     * Falls back to 'en' if locale is not supported.
     *
     * @return string
     */
    private function getImageLocale(): string
    {
        $locale = $this->localeResolver->getLocale();
        $languageCode = substr($locale, 0, 2);

        return in_array($languageCode, self::SUPPORTED_IMAGE_LOCALES, true)
            ? $languageCode
            : 'en';
    }

    /**
     * Sets context code ( for multiple stores ).
     *
     * @param int $storeId
     * @return void
     */
    public function setContextCode(int $storeId): void
    {
        $this->contextCode = $storeId;
    }
}
