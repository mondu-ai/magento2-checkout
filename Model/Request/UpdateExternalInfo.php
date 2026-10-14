<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class UpdateExternalInfo extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param Curl $curl
     * @param MonduFileLogger $monduFileLogger
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(
        Curl $curl,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly UrlBuilder $urlBuilder,
    ) {
        $this->curl = $curl;
    }

    /**
     * Sends the final order increment_id to Mondu after async order is confirmed by buyer.
     *
     * @param array $params  ['orderUid' => string, 'externalReferenceId' => string]
     * @throws LocalizedException
     * @return array
     */
    protected function request($params): array
    {
        $orderUid = $params['orderUid'] ?? null;
        $externalReferenceId = $params['externalReferenceId'] ?? null;

        if (!$orderUid || !$externalReferenceId) {
            throw new LocalizedException(__('UpdateExternalInfo: missing required params'));
        }

        $payload = json_encode(['external_reference_id' => $externalReferenceId]);
        $url = $this->urlBuilder->getUpdateExternalInfoUrl($orderUid);

        $this->monduFileLogger->info('UpdateExternalInfo: sending request', [
            'order_uuid'           => $orderUid,
            'external_reference_id' => $externalReferenceId,
        ]);

        $resultJson = $this->sendRequestWithParams('post', $url, $payload);

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu UpdateExternalInfo: empty response'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || isset($result['error'])) {
            $this->monduFileLogger->error('UpdateExternalInfo: API error', ['response' => $result]);
            throw new LocalizedException(__('Mondu UpdateExternalInfo: API error'));
        }

        return $result ?? [];
    }
}
