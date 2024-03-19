<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Mondu\Mondu\Model\LogFactory;

class ConfirmOrder extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ConfigProvider
     */
    protected $configProvider;

    /**
     * @var LogFactory
     */
    private $logger;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     * @param LogFactory $monduLogger
     */
    public function __construct(
        Curl $curl,
        ConfigProvider $configProvider,
        LogFactory $monduLogger
    ) {
        $this->curl = $curl;
        $this->configProvider = $configProvider;
        $this->logger = $monduLogger;
    }

    /**
     * @inheritDoc
     */
    protected function request($params)
    {
        $log = $this->getLogData($params['orderUid']);

        if ($log && $log['is_confirmed']) {
            return true;
        }

        $url = $this->configProvider->getApiUrl('orders') . '/' . $params['orderUid'] . '/confirm';

        $resultJson = $this->sendRequestWithParams(
            'post',
            $url,
            json_encode(['external_reference_id' => $params['referenceId']])
        );

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || isset($result['error'])) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        return $result;
    }

    /**
     * @param $orderUuid
     *
     * @return mixed
     */
    private function getLogData($orderUuid)
    {
        $monduLogger = $this->logger->create();

        $logCollection = $monduLogger->getCollection()
                                     ->addFieldToFilter('reference_id', ['eq' => $orderUuid])
                                     ->load();

        return $logCollection && $logCollection->getFirstItem()->getData() ? $logCollection->getFirstItem()->getData() : false;
    }
}
