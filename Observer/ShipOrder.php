<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Message\ManagerInterface;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\InvoiceOrderHelper;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class ShipOrder implements \Magento\Framework\Event\ObserverInterface
{
    protected $_monduLogger;
    private $_requestFactory;
    private $_config;
    private $monduFileLogger;
    private $paymentMethodHelper;
    private $orderHelper;
    private $messageManager;
    /**
     * @var InvoiceOrderHelper
     */
    private $invoiceOrderhelper;

    public function __construct(
        RequestFactory $requestFactory,
        ConfigProvider $config,
        Log $logger,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        OrderHelper $orderHelper,
        ManagerInterface $messageManager,
        InvoiceOrderHelper $invoiceOrderhelper
    )
    {
        $this->_requestFactory = $requestFactory;
        $this->_config = $config;
        $this->_monduLogger = $logger;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->orderHelper = $orderHelper;
        $this->messageManager = $messageManager;
        $this->invoiceOrderhelper = $invoiceOrderhelper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();

        $this->monduFileLogger->info('Entered ShipOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if(!$this->validateOrderPlacedWithMondu($order)) return;

        $monduLog = $this->_monduLogger->getLogCollection($order->getData('mondu_reference_id'));

        if($monduLog->getSkipShipObserver()) {
            $this->monduFileLogger->info('Already invoiced using invoice orders action, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }
        
        $monduId = $order->getData('mondu_reference_id');

        if(!$this->_monduLogger->canShipOrder($monduId)) {
            throw new LocalizedException(__('Can\'t ship order: Mondu order state must be confirmed or partially_shipped'));
        }

        $this->invoiceOrderhelper->handleInvoiceOrder($order, $shipment, $monduLog);
    }

    private function validateOrderPlacedWithMondu($order) {
        $payment = $order->getPayment();
        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return false;
        }

        return true;
    }
}
