<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Api\SearchCriteriaBuilder;
use \Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Exception\NotFoundException;
use \Mondu\Mondu\Model\LogFactory;
use Mondu\Mondu\Model\Request\Factory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Log extends AbstractHelper
{
    protected $_logger;
    private $_configProvider;
    private $_requestFactory;

    public function __construct(
        LogFactory $monduLogger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ConfigProvider $configProvider,
        Factory $requestFactory
    ) {
        $this->_logger = $monduLogger;
        $this->_configProvider = $configProvider;
        $this->_requestFactory = $requestFactory;
    }

    private function getLogCollection($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem();

        return $log;
    }

    public function logTransaction($order, $response, $addons = null)
    {
        $monduLogger = $this->_logger->create();
        $logData = array(
            'store_id' => $order->getStoreId(),
            'order_id' => $order->getId(),
            'reference_id' => $order->getMonduReferenceId(),
            // 'transaction_tstamp' => date('Y-m-d H:i:s',time()),
            'created_at' => $order->getCreatedAt(),
            'customer_id' => $order->getCustomerId(),
            'mondu_state' => $response['state'] ?? null,
            // 'mode' => $this->helper->getMode() ? 'sandbox' : 'live',
            'mode' => $this->_configProvider->getMode(),
            'addons' => json_encode($addons)
        );
        $monduLogger->addData($logData);
        $monduLogger->save();
    }

    public function getTransactionByOrderUid($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();
        return $log;
    }

    public function updateLogInvoice($orderUid, $addons)
    {
        $log = $this->getLogCollection($orderUid);

        $log->addData([
            'addons' => json_encode($addons)
        ]);

        $log->save();
    }

    public function updateLogMonduData($orderUid, $monduState = null, $viban = null, $addons = null)
    {
        $log = $this->getLogCollection($orderUid);

        if(empty($log->getData())) return;

        $data = [];
        if($monduState) {
            $data['mondu_state'] = $monduState;
        }

        if ($viban) {
            $data['invoice_iban'] = $viban;
        }

        if($addons) {
            $data['addons'] = json_encode($addons);
        }

        $log->addData($data);
        $log->save();
    }

    public function canShipOrder($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();

        if(@$log['mondu_state'] && (@$log['mondu_state'] === 'confirmed' || @$log['mondu_state'] === 'partially_shipped')) {
            return true;
        }

        return false;
    }

    public function syncOrder($orderUid)
    {
        $data = $this->_requestFactory->create(Factory::TRANSACTION_CONFIRM_METHOD)
            ->setValidate(false)
            ->process(['orderUid' => $orderUid]);
        $this->updateLogMonduData($orderUid, $data['order']['state'], @$data['order']['buyer']['viban']);
    }
}
