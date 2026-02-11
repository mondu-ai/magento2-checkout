<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Mondu\Mondu\Helpers\HeadersHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\ModuleHelper;

class Factory
{
    public const TRANSACTIONS_REQUEST_METHOD = 'CREATE_ORDER';
    public const TRANSACTION_CONFIRM_METHOD = 'GET_ORDER';
    public const SHIP_ORDER = 'CREATE_INVOICE';
    public const CANCEL = 'CANCEL_ORDER';
    public const MEMO = 'CREATE_CREDIT_NOTE';
    public const WEBHOOKS_KEYS_REQUEST_METHOD = 'GET_WEBHOOK_KEY';
    public const WEBHOOKS_REQUEST_METHOD = 'CREATE_WEBHOOK';
    public const ADJUST_ORDER = 'ADJUST_ORDER_2';
    public const EDIT_ORDER = 'ADJUST_ORDER';
    public const PAYMENT_METHODS = 'GET_PAYMENT_METHODS';
    public const ORDER_INVOICES = 'GET_ORDER_INVOICES';
    public const ERROR_EVENTS = 'CREATE_PLUGIN_EVENTS';
    public const CONFIRM_ORDER = 'CONFIRM_ORDER';

    /**
     * @var array
     */
    private array $invokableClasses = [
        self::TRANSACTIONS_REQUEST_METHOD => Transactions::class,
        self::TRANSACTION_CONFIRM_METHOD => Confirm::class,
        self::SHIP_ORDER => Ship::class,
        self::CANCEL => Cancel::class,
        self::MEMO => Memo::class,
        self::WEBHOOKS_KEYS_REQUEST_METHOD => Webhooks\Keys::class,
        self::WEBHOOKS_REQUEST_METHOD => Webhooks::class,
        self::ADJUST_ORDER => Adjust::class,
        self::EDIT_ORDER => Edit::class,
        self::PAYMENT_METHODS => PaymentMethods::class,
        self::ORDER_INVOICES => OrderInvoices::class,
        self::ERROR_EVENTS => ErrorEvents::class,
        self::CONFIRM_ORDER => ConfirmOrder::class,
    ];

    /**
     * @param HeadersHelper $headersHelper
     * @param ModuleHelper $moduleHelper
     * @param MonduFileLogger $monduFileLogger
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly HeadersHelper $headersHelper,
        private readonly ModuleHelper $moduleHelper,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    /**
     * Create class using object manager.
     *
     * @param string $method
     * @param int|null $storeId Store ID for multistore support
     * @param int|null $websiteId Website ID so webhook URL uses website scope (same as API key)
     * @throws LocalizedException
     * @return RequestInterface
     */
    public function create(string $method, ?int $storeId = null, ?int $websiteId = null): RequestInterface
    {
        $className = $this->invokableClasses[$method] ?? null;
        if ($className === null) {
            throw new LocalizedException(__('%1 method is not supported.'));
        }

        /** @var RequestInterface $model */
        $model = $this->objectManager->create($className);
        $model->setCommonHeaders($this->headersHelper->getHeaders())
            ->setEnvironmentInformation($this->moduleHelper->getEnvironmentInformation())
            ->setRequestOrigin($method);

        if ($storeId !== null && method_exists($model, 'setStoreId')) {
            $model->setStoreId($storeId);
        }

        if ($websiteId !== null && method_exists($model, 'setWebsiteId')) {
            $model->setWebsiteId($websiteId);
        }

        if ($method !== self::ERROR_EVENTS) {
            $model->setErrorEventsHandler($this->create(self::ERROR_EVENTS, $storeId));
        }

        return $model;
    }
}
