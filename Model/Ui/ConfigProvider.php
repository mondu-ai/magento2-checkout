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
     * @param EncryptorInterface $encryptor
     * @param ResourceConfig $resourceConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param TypeListInterface $cacheTypeList
     * @param UrlInterface $urlBuilder
     * @param WriterInterface $writer
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly ResourceConfig $resourceConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly UrlInterface $urlBuilder,
        private readonly WriterInterface $writer,
        private readonly StoreManagerInterface $storeManager,
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
     * Returns the webhook endpoint URL for a store.
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
     * Returns the webhook endpoint URL for a website (same scope as API key).
     *
     * Uses the default store of that website for the base URL.
     *
     * @param int|null $websiteId Website ID (optional)
     * @return string
     */
    public function getWebhookUrlForWebsite(?int $websiteId = null): string
    {
        if ($websiteId === null) {
            return $this->getWebhookUrl(null);
        }
        try {
            $website = $this->storeManager->getWebsite($websiteId);
            $store = $website->getDefaultStore();
            if (!$store) {
                $stores = $website->getStores();
                $store = !empty($stores) ? reset($stores) : null;
            }
            if ($store) {
                return $store->getBaseUrl(UrlInterface::URL_TYPE_WEB) . 'mondu/webhooks/index';
            }
        } catch (NoSuchEntityException $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // fall through to default
        }
        return $this->urlBuilder->getBaseUrl() . 'mondu/webhooks/index';
    }

    /**
     * Returns the configured API key.
     *
     * @param int|null $websiteId Optional website ID to get API key for specific website
     * @return string|null
     */
    public function getApiKey(?int $websiteId = null): ?string
    {
        // Use provided websiteId or get from context
        if ($websiteId === null) {
            $websiteId = $this->getWebsiteIdForContext();
        }

        if ($websiteId !== null) {
            return $this->scopeConfig->getValue(
                'payment/mondu/mondu_key',
                ScopeInterface::SCOPE_WEBSITE,
                $websiteId
            );
        }

        return $this->scopeConfig->getValue(
            'payment/mondu/mondu_key',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
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
            ? __($descriptionConfigMondu) : '';
        $descriptionMondusepa = $descriptionConfigMondusepa
            ? __($descriptionConfigMondusepa) : '';
        $descriptionMonduinstallment = $descriptionConfigMonduinstallment
            ? __($descriptionConfigMonduinstallment) : '';
        $descriptionMonduinstallmentByInvoice = $descriptionConfigMonduinstallmentByInvoice
            ? __($descriptionConfigMonduinstallmentByInvoice) : '';
        $descriptionMonduPayNow = $descriptionConfigMonduPayNow
            ? __($descriptionConfigMonduPayNow) : '';

        return [
            'payment' => [
                self::CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondu,
                    'title' => __($this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE)),
                ],
                self::SEPA_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMondusepa,
                    'title' => __($this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE)),
                ],
                self::INSTALLMENT_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallment,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallment/title', ScopeInterface::SCOPE_STORE)),
                ],
                self::INSTALLMENT_BY_INVOICE_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduinstallmentByInvoice,
                    'title' => __($this->scopeConfig
                        ->getValue('payment/monduinstallmentbyinvoice/title', ScopeInterface::SCOPE_STORE)),
                ],
                self::PAY_NOW_CODE => [
                    'sdkUrl' => $this->getSdkUrl(),
                    'monduCheckoutTokenUrl' => $this->urlBuilder->getUrl('mondu/payment_checkout/token'),
                    'description' => $descriptionMonduPayNow,
                    'title' => __($this->scopeConfig
                                      ->getValue('payment/mondupaynow/title', ScopeInterface::SCOPE_STORE)),
                ],
            ],
        ];
    }

    /**
     * Updates webhook secret for a specific website or globally.
     *
     * @param string $webhookSecret
     * @param int|null $websiteId Website ID for per-website secret storage
     * @return $this
     */
    public function updateWebhookSecret($webhookSecret = "", ?int $websiteId = null): self
    {
        if ($websiteId !== null) {
            $scope = ScopeInterface::SCOPE_WEBSITES;
            $scopeId = $websiteId;
        } else {
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeId = 0;
        }

        $this->resourceConfig->saveConfig(
            'payment/mondu/' . $this->getMode() . '_webhook_secret',
            $this->encryptor->encrypt($webhookSecret),
            $scope,
            $scopeId
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
     * Returns website ID for the current store context, if available.
     *
     * @return int|null
     */
    private function getWebsiteIdForContext(): ?int
    {
        if ($this->contextCode === null) {
            return null;
        }

        try {
            $store = $this->storeManager->getStore($this->contextCode);
            return (int) $store->getWebsiteId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
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
