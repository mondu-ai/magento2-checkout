<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\Request;

use Mondu\Mondu\Model\Ui\ConfigProvider;

class UrlBuilder
{
    /**
     * @param ConfigProvider $configProvider
     */
    public function __construct(private readonly ConfigProvider $configProvider)
    {
    }

    /**
     * @return string
     */
    public function getOrdersUrl(): string
    {
        return $this->build('orders');
    }

    /**
     * @param string $orderUid
     * @return string
     */
    public function getOrderUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}");
    }

    /**
     * @param string $orderUid
     * @return string
     */
    public function getOrderAdjustmentUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/adjust");
    }

    /**
     * @param string $orderUid
     * @return string
     */
    public function getOrderCancelUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/cancel");
    }

    /**
     * @param string $orderUid
     * @return string
     */
    public function getOrderConfirmUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/confirm");
    }

    /**
     * @param string $orderUid
     * @return string
     */
    public function getOrderInvoicesUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/invoices");
    }

    /**
     * @param string $invoiceUid
     * @return string
     */
    public function getInvoiceCreditNotesUrl(string $invoiceUid): string
    {
        return $this->build("invoices/{$invoiceUid}/credit_notes");
    }

    /**
     * @return string
     */
    public function getPaymentMethodsUrl(): string
    {
        return $this->build('payment_methods');
    }

    /**
     * @return string
     */
    public function getWebhooksUrl(): string
    {
        return $this->build('webhooks');
    }

    /**
     * @return string
     */
    public function getWebhookKeysUrl(): string
    {
        return $this->build('webhooks/keys');
    }

    /**
     * @return string
     */
    public function getPluginEventsUrl(): string
    {
        return $this->build('plugin/events');
    }

    /**
     * Returns the Mondu API URL based on the current mode.
     *
     * @param string|null $path
     * @return string
     */
    private function build(?string $path = null): string
    {
        $baseUrl = $this->configProvider->isSandboxModeEnabled()
            ? ConfigProvider::SANDBOX_API_URL
            : ConfigProvider::API_URL;

        return $baseUrl . ($path ? '/' . $path : '');
    }
}
