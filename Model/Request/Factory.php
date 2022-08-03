<?php
namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

class Factory
{
    const TRANSACTIONS_REQUEST_METHOD = 'transactions';
    const TRANSACTION_CONFIRM_METHOD = 'confirm';
    const SHIP_ORDER = 'ship';
    const CANCEL = 'cancel';
    const MEMO = 'memo';
    const WEBHOOKS_KEYS_REQUEST_METHOD = 'webhooks/keys';
    const WEBHOOKS_REQUEST_METHOD = 'webhooks';
    const ADJUST_ORDER = 'adjust';
    const EDIT_ORDER = 'edit';
    const PAYMENT_METHODS = 'payment_methods';
    const ORDER_INVOICES = 'order_invoices';

    private $monduFileLogger;

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
    ];

    private $objectManager;

    public function __construct(ObjectManagerInterface $objectManager, \Mondu\Mondu\Helpers\Logger\Logger $monduFileLogger)
    {
        $this->objectManager = $objectManager;
        $this->monduFileLogger = $monduFileLogger;
    }

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

        return $model;
    }
}
