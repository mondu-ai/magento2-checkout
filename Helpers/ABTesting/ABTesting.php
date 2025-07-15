<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\ABTesting;

class ABTesting
{
    protected const HOSTED_SOURCE = 'hosted';
    protected const WIDGET_SOURCE = 'widget';

    /**
     * Formats the API response and extracts Mondu order data.
     *
     * @param array $result
     * @return array
     */
    public function formatApiResult(array $result): array
    {
        if ($result['error']) {
            return $result;
        }

        $order = $result['body']['order'] ?? [];

        return [
            'error' => false,
            'message' => $result['message'],
            'token' => $order['token'] ?? null,
            'hosted_checkout_url' => $order['hosted_checkout_url'] ?? null,
            'source' => $this->isHostedCheckout($order) ? self::HOSTED_SOURCE : self::WIDGET_SOURCE,
        ];
    }

    /**
     * Checks if the order uses hosted checkout.
     *
     * @param array $monduOrder
     * @return bool
     */
    protected function isHostedCheckout(array $monduOrder): bool
    {
        return isset($monduOrder['hosted_checkout_url']);
    }
}
