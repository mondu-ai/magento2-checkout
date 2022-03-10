<?php
namespace Mondu\Mondu\Observer;

use Error;
use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder implements \Magento\Framework\Event\ObserverInterface
{
    const CODE = 'mondu';

    private $_checkoutSession;
    private $_requestFactory;
    private $_monduLogger;

    public function __construct(CheckoutSession $checkoutSession, RequestFactory $requestFactory, \Mondu\Mondu\Helpers\Log $logger)
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_monduLogger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderUid = $this->_checkoutSession->getMonduid();
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        if ($payment->getCode() != self::CODE && $payment->getMethod() != self::CODE) {
            return;
        }

        if ($order->getRelationParentRealId() || $order->getRelationParentId()) {
            throw new LocalizedException(__('Mondu currently doesnt support order editing'));
        }

        try {
            $orderData = $this->_requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];
            $order->setData('mondu_reference_id', $orderUid);
            $order->addStatusHistoryComment(__('Mondu: payment accepted for %1', $orderUid));

            $shippingAddress = $order->getShippingaddress();
            $billingAddress = $order->getBillingaddress();

            $shippingAddress->setData('country_id', $orderData['shipping_address']['country_code']);
            $shippingAddress->setData('city', $orderData['shipping_address']['city']);
            $shippingAddress->setData('postcode', $orderData['shipping_address']['zip_code']);
            $shippingAddress->setData('street', $orderData['shipping_address']['address_line1'] . ' ' . $orderData['shipping_address']['address_line2']);

            $billingAddress->setData('country_id', $orderData['billing_address']['country_code']);
            $billingAddress->setData('city', $orderData['billing_address']['city']);
            $billingAddress->setData('postcode', $orderData['billing_address']['zip_code']);
            $billingAddress->setData('street', $orderData['billing_address']['address_line1'] . ' ' . $orderData['billing_address']['address_line2']);

            $order->save();
            $this->_monduLogger->logTransaction($order, $orderData, null);
        } catch (Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
