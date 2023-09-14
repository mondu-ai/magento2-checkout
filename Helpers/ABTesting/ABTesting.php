<?php
namespace Mondu\Mondu\Helpers\ABTesting;

class ABTesting
{
    protected const HOSTED_SOURCE = 'hosted';
    protected const WIDGET_SOURCE = 'widget';

    public function formatApiResult($result)
    {
        $body = $result['body'];

        $response = [
            'error' => $result['error'],
            'message' => $result['message'],
            'token' => $result['body']['order']['token'] ?? null,
            'hosted_checkout_url' => $result['body']['order']['hosted_checkout_url'] ?? null
        ];

        if ($this->isHostedCheckout($body['order'])) {
            $response['source'] = self::HOSTED_SOURCE;
        } else {
            $response['source'] = self::WIDGET_SOURCE;
        }

        return $response;
    }

    protected function isHostedCheckout($monduOrder)
    {
        return true;
        return $monduOrder['hosted_checkout_url'] !== null;
    }
}
