<?php
namespace Mondu\Mondu\Observer;

use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Mondu\Mondu\Helpers\OrderHelper;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CreateOrder implements \Magento\Framework\Event\ObserverInterface
{
    const CODE = 'mondu';

    private $_checkoutSession;
    private $_requestFactory;
    private $_monduLogger;
    private $quoteFactory;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestFactory $requestFactory,
        \Mondu\Mondu\Helpers\Log $logger,
        QuoteFactory $quoteFactory,
        OrderHelper $orderHelper
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_monduLogger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderHelper = $orderHelper;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderUid = $this->_checkoutSession->getMonduid();
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $log = true;

        if ($payment->getCode() != self::CODE && $payment->getMethod() != self::CODE) {
            return;
        }

        if ($order->getRelationParentRealId() || $order->getRelationParentId()) {
            $this->handleOrderAdjustment($order);
            $orderUid = $order->getMonduReferenceId();
            $log = false;
        }

        try {
            $orderData = $this->_requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD)
                ->setValidate(true)
                ->process(['orderUid' => $orderUid]);

            $orderData = $orderData['order'];
            $order->setData('mondu_reference_id', $orderUid);

            if($log) {
                $order->addStatusHistoryComment(__('Mondu: payment accepted for %1', $orderUid));
            }

            $shippingAddress = $order->getShippingaddress();
            $billingAddress = $order->getBillingaddress();

            $shippingAddress->setData('country_id', $orderData['shipping_address']['country_code']);
            $shippingAddress->setData('city', $orderData['shipping_address']['city']);
            $shippingAddress->setData('postcode', $orderData['shipping_address']['zip_code']);
            $shippingAddress->setData('street', $orderData['shipping_address']['address_line1'] . ' ' . $orderData['shipping_address']['address_line2']);
            $shippingAddress->setData('company', $orderData['buyer']['company_name']);

            $billingAddress->setData('country_id', $orderData['billing_address']['country_code']);
            $billingAddress->setData('city', $orderData['billing_address']['city']);
            $billingAddress->setData('postcode', $orderData['billing_address']['zip_code']);
            $billingAddress->setData('street', $orderData['billing_address']['address_line1'] . ' ' . $orderData['billing_address']['address_line2']);
            $billingAddress->setData('company', $orderData['buyer']['company_name']);
            $order->save();

            if($log) {
                $this->_monduLogger->logTransaction($order, $orderData, null);
            } else {
                $this->_monduLogger->updateLogMonduData($orderUid, null, null, null, $order->getId());
            }

        } catch (Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }
    }

    public function handleOrderAdjustment($order) {
        $prevOrderId = $order->getRelationParentId();
        $log = $this->_monduLogger->getTransactionByIncrementId($prevOrderId);

        if(!$log || !$log['reference_id']) {
            throw new LocalizedException(__('This order was not placed with mondu'));
        }
        $orderUid = $log['reference_id'];
        $quote = $this->quoteFactory->create()->load($order->getQuoteId());
        $quote->collectTotals();
        $lines = $this->orderHelper->getLinesFromOrder($order);
        $netPrice = $quote->getGrandTotal() - $quote->getShippingAddress()->getBaseTaxAmount();

        $adjustment =  [
            'currency' => $quote->getBaseCurrencyCode(),
            'external_reference_id' => $order->getIncrementId(),
            'amount' => [
                'net_price_cents' => $netPrice * 100,
                'tax_cents' => $quote->getShippingAddress()->getBaseTaxAmount() * 100
            ],
            'lines' => $lines
        ];
        try {
            $editData = $this->_requestFactory->create(RequestFactory::EDIT_ORDER)
                ->setOrderUid($orderUid)
                ->process($adjustment);

            //TODO change 2 api calls for 1 on edit order
            $order->setData('mondu_reference_id', $orderUid);
            $order->addStatusHistoryComment(__('Mondu: payment adjusted for %1', $orderUid));
        } catch (Exception $e) {
            $orderPayment = $order->getPayment();
            $orderPayment->deny(false);
            $order->setStatus(Order::STATE_CANCELED);
            $order->save();
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
