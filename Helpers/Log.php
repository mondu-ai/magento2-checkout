<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Model\LogFactory;
use Mondu\Mondu\Model\Request\Factory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Log
{
    public const MONDU_STATE_CONFIRMED = 'confirmed';
    public const MONDU_STATE_PARTIALLY_SHIPPED = 'partially_shipped';
    public const MONDU_STATE_PARTIALLY_COMPLETE = 'partially_complete';
    public const MONDU_STATE_SHIPPED = 'shipped';
    public const MONDU_STATE_COMPLETE = 'complete';

    /**
     * @param ConfigProvider $configProvider
     * @param Factory $requestFactory
     * @param LogFactory $monduLogger
     * @param MonduTransactionItem $monduTransactionItem
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly Factory $requestFactory,
        private readonly LogFactory $monduLogger,
        private readonly MonduTransactionItem $monduTransactionItem,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Returns the first log record by order UUID.
     *
     * @param string $orderUid
     * @throws LocalizedException
     * @return DataObject
     */
    public function getLogCollection(string $orderUid)
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        return $logCollection->getFirstItem();
    }

    /**
     * Saves Mondu transaction log for the order.
     *
     * @param OrderInterface $order
     * @param array $response
     * @param array|null $addons
     * @param string $paymentMethod
     * @throws Exception
     * @return void
     */
    public function logTransaction(
        OrderInterface $order,
        array $response,
        ?array $addons = null,
        string $paymentMethod = 'mondu'
    ): void {
        $monduLogger = $this->monduLogger->create();
        $logData = [
            'store_id' => $order->getStoreId(),
            'order_id' => $order->getId() ? $order->getId() : $order->getEntityId(),
            'reference_id' => $order->getMonduReferenceId(),
            'created_at' => $order->getCreatedAt(),
            'customer_id' => $order->getCustomerId(),
            'mondu_state' => $response['state'] ?? null,
            'mode' => $this->configProvider->getMode(),
            'addons' => $this->serializer->serialize($addons),
            'payment_method' => $paymentMethod,
            'authorized_net_term' => $response['authorized_net_term'],
            'is_confirmed' => 1,
            'invoice_iban' => $response['merchant']['viban'] ?? null,
            'external_data' => $this->serializer->serialize([
                'merchant_company_name' => $response['merchant']['company_name'] ?? null,
                'buyer_country_code' => $response['content_configuration']['buyer_country_code'] ?? null,
                'bank_account' => $response['bank_account'] ?? null,
            ]),
        ];
        $monduLogger->addData($logData);
        $monduLogger->save();

        $this->monduTransactionItem->createTransactionItemsForOrder((int) $monduLogger->getId(), $order);
    }

    /**
     * Returns transaction by UUID.
     *
     * @param string $orderUid
     * @param bool $isCollection
     * @throws LocalizedException
     * @return DataObject
     */
    public function getTransactionByOrderUid(string $orderUid, bool $isCollection = false)
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        if ($isCollection) {
            return $logCollection->getFirstItem();
        }

        return $logCollection->getFirstItem()->getData();
    }

    /**
     * Returns transaction by order id.
     *
     * @param int $orderId
     * @throws LocalizedException
     * @return array|mixed|null
     */
    public function getTransactionByIncrementId(int $orderId)
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('order_id', ['eq' => $orderId])
            ->load();

        return $logCollection->getFirstItem()->getData();
    }

    /**
     * Updates invoice mapping in the log.
     *
     * @param string $orderUid
     * @param array $addons
     * @param bool $skipObserver
     * @throws LocalizedException
     * @return void
     */
    public function updateLogInvoice(string $orderUid, array $addons, bool $skipObserver = false): void
    {
        $log = $this->getLogCollection($orderUid);

        $log->addData(['addons' => $this->serializer->serialize($addons)]);

        if ($skipObserver) {
            $log->addData(['skip_ship_observer' => true]);
        }

        $log->save();
    }

    /**
     * Marks the log to skip shipment observer.
     *
     * @param string $orderUid
     * @param bool $skipObserver
     * @throws LocalizedException
     * @return void
     */
    public function updateLogSkipObserver(string $orderUid, bool $skipObserver): void
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
     * Updates log fields such as state, IBAN, addons, and more.
     *
     * @param string $orderUid
     * @param string|null $monduState
     * @param string|null $viban
     * @param array $addons
     * @param int|string $orderId
     * @param string $paymentMethod
     * @throws LocalizedException
     * @return mixed
     */
    public function updateLogMonduData(
        string $orderUid,
        ?string $monduState = null,
        ?string $viban = null,
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

            if ($monduState === self::MONDU_STATE_CONFIRMED) {
                $data['is_confirmed'] = 1;
            }
        }

        if ($viban) {
            $data['invoice_iban'] = $viban;
        }

        if ($addons) {
            $data['addons'] = $this->serializer->serialize($addons);
        }

        if ($orderId) {
            $data['order_id'] = $orderId;
        }

        if ($paymentMethod) {
            $data['payment_method'] = $paymentMethod;
        }

        $log->addData($data);
        $log->save();

        return (int) $log->getId();
    }

    /**
     * Check if the order can be shipped based on Mondu state.
     *
     * @param string $orderUid
     * @throws LocalizedException
     * @return bool
     */
    public function canShipOrder(string $orderUid): bool
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();

        return (bool) (isset($log['mondu_state']) && (
            $log['mondu_state'] === self::MONDU_STATE_CONFIRMED
                || $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_SHIPPED
                || $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_COMPLETE
        ));
    }

    /**
     * Check if a credit memo can be created based on Mondu state.
     *
     * @param string $orderUid
     * @throws LocalizedException
     * @return bool
     */
    public function canCreditMemo(string $orderUid): bool
    {
        $monduLogger = $this->monduLogger->create();

        $logCollection = $monduLogger->getCollection()
            ->addFieldToFilter('reference_id', ['eq' => $orderUid])
            ->load();

        $log = $logCollection->getFirstItem()->getData();

        return (bool) (isset($log['mondu_state']) && (
            $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_SHIPPED
                || $log['mondu_state'] === self::MONDU_STATE_SHIPPED
                || $log['mondu_state'] === self::MONDU_STATE_PARTIALLY_COMPLETE
                || $log['mondu_state'] === self::MONDU_STATE_COMPLETE
        ));
    }

    /**
     * Syncs order state with Mondu API and updates the log.
     *
     * @param string $orderUid
     * @throws LocalizedException
     * @return void
     */
    public function syncOrder(string $orderUid): void
    {
        $data = $this->requestFactory->create(Factory::TRANSACTION_CONFIRM_METHOD)
            ->setValidate(false)
            ->process(['orderUid' => $orderUid]);
        $this->updateLogMonduData($orderUid, $data['order']['state'], $data['order']['merchant']['viban'] ?? null);
    }

    /**
     * Syncs order invoices with Mondu API and updates the log.
     *
     * @param string $orderUid
     * @throws LocalizedException
     * @return void
     */
    public function syncOrderInvoices(string $orderUid): void
    {
        $data = $this->requestFactory->create(Factory::ORDER_INVOICES)
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
                'uuid' => $monduInvoice['uuid'],
            ];
        }

        $this->updateLogMonduData($orderUid, null, null, $addons);
    }
}
