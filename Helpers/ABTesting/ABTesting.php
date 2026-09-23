<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\ABTesting;

class ABTesting
{
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
        ];
    }
}
