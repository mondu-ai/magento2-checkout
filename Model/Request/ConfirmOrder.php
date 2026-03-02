<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\LogFactory;

class ConfirmOrder extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param Curl $curl
     * @param LogFactory $monduLogger
     * @param UrlBuilder $urlBuilder
     * @param MonduFileLogger $monduFileLogger
     */
    public function __construct(
        Curl $curl,
        private readonly LogFactory $monduLogger,
        private readonly UrlBuilder $urlBuilder,
        private readonly MonduFileLogger $monduFileLogger,
    ) {
        $this->curl = $curl;
    }

    /**
     * Sends confirm order request to Mondu if not already confirmed.
     *
     * @param array $params
     * @return mixed
     * @throws LocalizedException
     */
    protected function request($params)
    {
        $log = $this->getLogData($params['orderUid']);

        if ($log && $log['is_confirmed']) {
            return true;
        }

        $payload = json_encode(['external_reference_id' => $params['referenceId']]);
        $confirmUrl = $this->urlBuilder->getOrderConfirmUrl($params['orderUid']);

        $resultJson = $this->sendRequestWithParams('post', $confirmUrl, $payload);

        if (!$resultJson) {
            $this->monduFileLogger->error('ConfirmOrder: empty response from Mondu', [
                'order_uid' => $params['orderUid'],
            ]);
            throw new LocalizedException(__('Mondu: Something went wrong'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || isset($result['error'])) {
            throw new LocalizedException(__('Mondu: Something went wrong'));
        }

        return $result;
    }

    /**
     * Returns existing log data for the given order UID.
     *
     * @param string $orderUuid
     * @throws LocalizedException
     * @return mixed
     */
    private function getLogData(string $orderUuid)
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUuid])
            ->load();

        return $logCollection && $logCollection->getFirstItem()->getData()
            ? $logCollection->getFirstItem()->getData()
            : false;
    }
}
