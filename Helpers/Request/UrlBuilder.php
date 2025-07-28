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
     * Returns the API URL for listing all orders.
     *
     * @return string
     */
    public function getOrdersUrl(): string
    {
        return $this->build('orders');
    }

    /**
     * Returns the API URL for retrieving a specific order.
     *
     * @param string $orderUid
     * @return string
     */
    public function getOrderUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}");
    }

    /**
     * Returns the API URL for adjusting an existing order.
     *
     * @param string $orderUid
     * @return string
     */
    public function getOrderAdjustmentUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/adjust");
    }

    /**
     * Returns the API URL for canceling an order.
     *
     * @param string $orderUid
     * @return string
     */
    public function getOrderCancelUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/cancel");
    }

    /**
     * Returns the API URL for confirming an order.
     *
     * @param string $orderUid
     * @return string
     */
    public function getOrderConfirmUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/confirm");
    }

    /**
     * Returns the API URL for retrieving order invoices.
     *
     * @param string $orderUid
     * @return string
     */
    public function getOrderInvoicesUrl(string $orderUid): string
    {
        return $this->build("orders/{$orderUid}/invoices");
    }

    /**
     * Returns the API URL for retrieving credit notes related to an invoice.
     *
     * @param string $invoiceUid
     * @return string
     */
    public function getInvoiceCreditNotesUrl(string $invoiceUid): string
    {
        return $this->build("invoices/{$invoiceUid}/credit_notes");
    }

    /**
     * Returns the API URL for retrieving available payment methods.
     *
     * @return string
     */
    public function getPaymentMethodsUrl(): string
    {
        return $this->build('payment_methods');
    }

    /**
     * Returns the API URL for webhook registration.
     *
     * @return string
     */
    public function getWebhooksUrl(): string
    {
        return $this->build('webhooks');
    }

    /**
     * Returns the API URL for retrieving webhook signing keys.
     *
     * @return string
     */
    public function getWebhookKeysUrl(): string
    {
        return $this->build('webhooks/keys');
    }

    /**
     * Returns the API URL for listing plugin-triggered events.
     *
     * @return string
     */
    public function getPluginEventsUrl(): string
    {
        return $this->build('plugin/events');
    }

    /**
     * Builds the full Mondu API URL based on the path and environment mode.
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
