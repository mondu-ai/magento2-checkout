<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\LogFactory;
use Mondu\Mondu\Model\Request\Factory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Log extends AbstractHelper
{
    const MONDU_STATE_CONFIRMED = 'confirmed';
    const MONDU_STATE_PARTIALLY_SHIPPED = 'partially_shipped';
    const MONDU_STATE_PARTIALLY_COMPLETE = 'partially_complete';
    const MONDU_STATE_SHIPPED = 'shipped';
    const MONDU_STATE_COMPLETE = 'complete';

    /**
     * @var LogFactory
     */
    protected $_logger;

    /**
     * @var ConfigProvider
     */
    private $_configProvider;

    /**
     * @var Factory
     */
    private $_requestFactory;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var MonduTransactionItem
     */
    private $monduTransactionItem;

    /**
     * @param LogFactory $monduLogger
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ConfigProvider $configProvider
     * @param Factory $requestFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param MonduTransactionItem $monduTransactionItem
     */
    public function __construct(
        LogFactory $monduLogger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ConfigProvider $configProvider,
        Factory $requestFactory,
        OrderRepositoryInterface $orderRepository,
        MonduTransactionItem $monduTransactionItem
    ) {
        $this->_logger = $monduLogger;
        $this->_configProvider = $configProvider;
        $this->_requestFactory = $requestFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->monduTransactionItem = $monduTransactionItem;
    }

    /**
     * Get first DB record
     *
     * @param string $orderUid
     * @return \Magento\Framework\DataObject
     */
    public function getLogCollection($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        return $logCollection->getFirstItem();
    }

    /**
     * Save item to DB
     *
     * @param Order $order
     * @param array $response
     * @param array|null $addons
     * @param string $paymentMethod
     * @return void
     * @throws \Exception
     */
    public function logTransaction($order, $response, $addons = null, $paymentMethod = 'mondu')
    {
        $monduLogger = $this->_logger->create();
        $logData = [
            'store_id' => $order->getStoreId(),
            'order_id' => $order->getId() ? $order->getId() : $order->getEntityId(),
            'reference_id' => $order->getMonduReferenceId(),
            'created_at' => $order->getCreatedAt(),
            'customer_id' => $order->getCustomerId(),
            'mondu_state' => $response['state'] ?? null,
            'mode' => $this->_configProvider->getMode(),
            'addons' => json_encode($addons),
            'payment_method' => $paymentMethod,
            'authorized_net_term' => $response['authorized_net_term'],
            'is_confirmed' => true,
            'invoice_iban' => $response['merchant']['viban'] ?? null,
            'external_data' => json_encode([
                'merchant_company_name' => $response['merchant']['company_name'] ?: null,
                'buyer_country_code'    => $response['content_configuration']['buyer_country_code'] ?: null,
                'bank_account'          => $response['bank_account'] ?: null
            ])
        ];
        $monduLogger->addData($logData);
        $monduLogger->save();

        $this->monduTransactionItem->createTransactionItemsForOrder($monduLogger->getId(), $order);
    }

    /**
     * Get by order UUID
     *
     * @param string $orderUid
     * @param mixed $collection
     * @return array|\Magento\Framework\DataObject|mixed|null
     */
    public function getTransactionByOrderUid($orderUid, $collection = false)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        if ($collection) {
            return $logCollection->getFirstItem();
        }

        return $logCollection->getFirstItem()->getData();
    }

    /**
     * Get by increment ID
     *
     * @param string $incrementId
     * @return array|mixed|null
     */
    public function getTransactionByIncrementId($incrementId)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('order_id', ['eq' => $incrementId])
            ->load();

        $log = $logCollection->getFirstItem()->getData();
        return $log;
    }

    /**
     * Update addons with invoice data
     *
     * @param string $orderUid
     * @param array $addons
     * @param bool $skipObserver
     * @return void
     */
    public function updateLogInvoice($orderUid, $addons, $skipObserver = false)
    {
        $log = $this->getLogCollection($orderUid);

        $log->addData([
            'addons' => json_encode($addons)
        ]);

        if ($skipObserver) {
            $log->addData([
                'skip_ship_observer' => true
            ]);
        }

        $log->save();
    }

    /**
     * Update DB record skip_ship_observer field
     *
     * @param string $orderUid
     * @param bool $skipObserver
     * @return void
     */
    public function updateLogSkipObserver($orderUid, $skipObserver)
    {
        $log = $this->getTransactionByOrderUid($orderUid, true);

        if (empty($log->getData())) {
            return;
        }

        $log->setData('skip_ship_observer', $skipObserver);
        $log->setDataChanges(true);
        $log->save();
    }

    /**
     * Update DB record by uuid
     *
     * @param string $orderUid
     * @param string $monduState
     * @param string $viban
     * @param array $addons
     * @param string|int $orderId
     * @param string $paymentMethod
     * @return mixed
     */
    public function updateLogMonduData(
        $orderUid,
        $monduState = null,
        $viban = null,
        $addons = null,
        $orderId = null,
        $paymentMethod = null
    ) {
        $log = $this->getLogCollection($orderUid);

        if (empty($log->getData())) {
            return;
        }

        $data = [];
        if ($monduState) {
            $data['mondu_state'] = $monduState;

            if ($monduState == self::MONDU_STATE_CONFIRMED) {
                $data['is_confirmed'] = 1;
            }
        }

        if ($viban) {
            $data['invoice_iban'] = $viban;
        }

        if ($addons) {
            $data['addons'] = json_encode($addons);
        }

        if ($orderId) {
            $data['order_id'] = $orderId;
        }

        if ($paymentMethod) {
            $data['payment_method'] = $paymentMethod;
        }

        $log->addData($data);
        $log->save();

        return $log->getId();
    }

    /**
     * Check if can Ship the order
     *
     * @param string $orderUid
     * @return bool
     */
    public function canShipOrder($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();

        if (isset($log['mondu_state']) && (
                $log['mondu_state'] === self::MONDU_STATE_CONFIRMED ||
                $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_SHIPPED ||
                $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_COMPLETE
        )) {
            return true;
        }

        return false;
    }

    /**
     * Check if can create Credit Note
     *
     * @param string $orderUid
     * @return bool
     */
    public function canCreditMemo($orderUid)
    {
        $monduLogger = $this->_logger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();

        if (isset($log['mondu_state']) && (
                $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_SHIPPED ||
                $log['mondu_state'] === self::MONDU_STATE_SHIPPED ||
                $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_COMPLETE ||
                $log['mondu_state'] === self::MONDU_STATE_COMPLETE
        )) {
            return true;
        }

        return false;
    }

    /**
     * Sync local order data with Mondu Api
     *
     * @param string $orderUid
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function syncOrder($orderUid)
    {
        $data = $this->_requestFactory->create(Factory::TRANSACTION_CONFIRM_METHOD)
            ->setValidate(false)
            ->process(['orderUid' => $orderUid]);
        $this->updateLogMonduData($orderUid, $data['order']['state'], $data['order']['merchant']['viban'] ?? null);
    }

    /**
     * Sync Order invoices with Mondu Api
     *
     * @param string $orderUid
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function syncOrderInvoices($orderUid)
    {
        $data = $this->_requestFactory->create(Factory::ORDER_INVOICES)
            ->process(['order_uuid' => $orderUid]);

        if (!count($data)) {
            return;
        }

        $searchCriteria = $this->searchCriteriaBuilder->addFilter(
            'mondu_reference_id',
            $orderUid
        )->create();

        $result = $this->orderRepository->getList($searchCriteria);
        $orders = $result->getItems();
        $order = end($orders);
        $invoiceNumberIdMap = [];

        foreach ($order->getInvoiceCollection() as $i) {
            $invoiceNumberIdMap[$i->getIncrementId()] = $i->getId();
        }

        $addons = [];

        foreach ($data as $monduInvoice) {
            $addons[$monduInvoice['invoice_number']] = [
                'local_id' => $invoiceNumberIdMap[$monduInvoice['invoice_number']] ?? null,
                'state' => $monduInvoice['state'],
                'uuid' => $monduInvoice['uuid']
            ];
        }

        $this->updateLogMonduData($orderUid, null, null, $addons);
    }
}
