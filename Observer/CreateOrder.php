<?php
namespace Mondu\Mondu\Observer;

use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder implements \Magento\Framework\Event\ObserverInterface
{
    private $_checkoutSession;
    private $_requestFactory;
    private $_monduLogger;
    private $quoteFactory;
    private $monduFileLogger;
    private $paymentMethodHelper;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        QuoteFactory $quoteFactory,
        OrderHelper $orderHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_monduLogger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderHelper = $orderHelper;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderUid = $this->_checkoutSession->getMonduid();
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $createMonduDatabaseRecord = true;

        $this->monduFileLogger->info('Entered CreateOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a Mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        if ($order->getRelationParentRealId() || $order->getRelationParentId()) {
            $this->monduFileLogger->info('Order has parent id, adjusting order in Mondu. ', ['orderNumber' => $order->getIncrementId()]);
            $this->handleOrderAdjustment($order);
            $orderUid = $order->getMonduReferenceId();
            $createMonduDatabaseRecord = false;
        }

        try {
            $this->monduFileLogger->info('Validating order status in Mondu. ', ['orderNumber' => $order->getIncrementId()]);
            $orderData = $this->_requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];
            $order->setData('mondu_reference_id', $orderUid);

            if($createMonduDatabaseRecord) {
                $order->addStatusHistoryComment(__('Mondu: order id %1', $orderUid));
            }

            $shippingAddress = $order->getShippingaddress();
            $billingAddress = $order->getBillingaddress();

            $shippingAddress->setData('country_id', $orderData['shipping_address']['country_code']);
            $shippingAddress->setData('city', $orderData['shipping_address']['city']);
            $shippingAddress->setData('postcode', $orderData['shipping_address']['zip_code']);
            //$shippingAddress->setData('street', $orderData['shipping_address']['address_line1'] . ' ' . $orderData['shipping_address']['address_line2']);
            $shippingAddress->setData('company', $orderData['buyer']['company_name']);

            $billingAddress->setData('country_id', $orderData['billing_address']['country_code']);
            $billingAddress->setData('city', $orderData['billing_address']['city']);
            $billingAddress->setData('postcode', $orderData['billing_address']['zip_code']);
            //$billingAddress->setData('street', $orderData['billing_address']['address_line1'] . ' ' . $orderData['billing_address']['address_line2']);
            $billingAddress->setData('company', $orderData['buyer']['company_name']);
            $order->save();
            $this->monduFileLogger->info('Saved the order in Magento ', ['orderNumber' => $order->getIncrementId()]);

            if($createMonduDatabaseRecord) {
                $this->_monduLogger->logTransaction($order, $orderData, null, $this->paymentMethodHelper->getCode($payment));
            } else {
                $this->_monduLogger->updateLogMonduData($orderUid, null, null, null, $order->getId());
            }

        } catch (Exception $e) {
            $this->monduFileLogger->info('Error in CreateOrder observer', ['orderNumber' => $order->getIncrementId()]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }

    /**
     * @throws LocalizedException
     */
    public function handleOrderAdjustment($order) {
        $this->orderHelper->handleOrderAdjustment($order);
    }
}
