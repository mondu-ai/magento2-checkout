<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\ABTesting;

class ABTesting
{
    /**
     * Only Hyvä Checkout below 1.0.8 reads the source, and hosted checkout is
     * the only one left.
     *
     * @deprecated 2.9.2 This constant will be removed in version 2.9.3 without replacement
     */
    protected const HOSTED_SOURCE = 'hosted';

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
            // Hyvä Checkout below 1.0.8 reads this key unchecked, keep it until 2.9.3.
            'source' => self::HOSTED_SOURCE,
        ];
    }
}
