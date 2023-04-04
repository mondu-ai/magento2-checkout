<?php
namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Mondu\Mondu\Helpers\HeadersHelper;
use Mondu\Mondu\Helpers\Logger\Logger;
use Mondu\Mondu\Helpers\ModuleHelper;

class Factory
{
    const TRANSACTIONS_REQUEST_METHOD = 'CREATE_ORDER';
    const TRANSACTION_CONFIRM_METHOD = 'GET_ORDER';
    const SHIP_ORDER = 'CREATE_INVOICE';
    const CANCEL = 'CANCEL_ORDER';
    const MEMO = 'CREATE_CREDIT_NOTE';
    const WEBHOOKS_KEYS_REQUEST_METHOD = 'GET_WEBHOOK_KEY';
    const WEBHOOKS_REQUEST_METHOD = 'CREATE_WEBHOOK';
    const ADJUST_ORDER = 'ADJUST_ORDER_2';
    const EDIT_ORDER = 'ADJUST_ORDER';
    const PAYMENT_METHODS = 'GET_PAYMENT_METHODS';
    const ORDER_INVOICES = 'GET_ORDER_INVOICES';
    const ERROR_EVENTS = 'CREATE_PLUGIN_EVENTS';

    /**
     * @var Logger
     */
    private $monduFileLogger;
    /**
     * @var HeadersHelper
     */
    private $headersHelper;

    /**
     * @var ModuleHelper
     */
    private $moduleHelper;

    /**
     * @var string[]
     */
    private $invokableClasses = [
        self::TRANSACTIONS_REQUEST_METHOD => \Mondu\Mondu\Model\Request\Transactions::class,
        self::TRANSACTION_CONFIRM_METHOD => \Mondu\Mondu\Model\Request\Confirm::class,
        self::SHIP_ORDER => \Mondu\Mondu\Model\Request\Ship::class,
        self::CANCEL => \Mondu\Mondu\Model\Request\Cancel::class,
        self::MEMO => \Mondu\Mondu\Model\Request\Memo::class,
        self::WEBHOOKS_KEYS_REQUEST_METHOD => \Mondu\Mondu\Model\Request\Webhooks\Keys::class,
        self::WEBHOOKS_REQUEST_METHOD => \Mondu\Mondu\Model\Request\Webhooks::class,
        self::ADJUST_ORDER => \Mondu\Mondu\Model\Request\Adjust::class,
        self::EDIT_ORDER => \Mondu\Mondu\Model\Request\Edit::class,
        self::PAYMENT_METHODS => \Mondu\Mondu\Model\Request\PaymentMethods::class,
        self::ORDER_INVOICES => \Mondu\Mondu\Model\Request\OrderInvoices::class,
        self::ERROR_EVENTS => \Mondu\Mondu\Model\Request\ErrorEvents::class,
    ];

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    public function __construct(
        ObjectManagerInterface $objectManager,
        Logger $monduFileLogger,
        HeadersHelper $headersHelper,
        ModuleHelper $moduleHelper
    ) {
        $this->objectManager = $objectManager;
        $this->monduFileLogger = $monduFileLogger;
        $this->headersHelper = $headersHelper;
        $this->moduleHelper = $moduleHelper;
    }

    /**
     * @throws LocalizedException
     */
    public function create($method)
    {
        $className = !empty($this->invokableClasses[$method])
            ? $this->invokableClasses[$method]
            : null;

        if ($className === null) {
            throw new LocalizedException(
                __('%1 method is not supported.')
            );
        }

        $this->monduFileLogger->info('Sending a request to mondu api, action: '. $method);
        $model = $this->objectManager->create($className);
        $model->setCommonHeaders($this->headersHelper->getHeaders())
            ->setEnvironmentInformation($this->moduleHelper->getEnvironmentInformation())
            ->setRequestOrigin($method);

        if ($method !== self::ERROR_EVENTS) {
            $model->setErrorEventsHandler($this->create(self::ERROR_EVENTS));
        }

        return $model;
    }
}
