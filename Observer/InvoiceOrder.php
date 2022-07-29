<?php
namespace Mondu\Mondu\Observer;

use Error;
use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class InvoiceOrder implements \Magento\Framework\Event\ObserverInterface
{
    private $_checkoutSession;
    private $_requestFactory;

    public function __construct(CheckoutSession $checkoutSession, RequestFactory $requestFactory)
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        return;
    }
}
