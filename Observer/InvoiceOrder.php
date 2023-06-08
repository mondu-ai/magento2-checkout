<?php
namespace Mondu\Mondu\Observer;

use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class InvoiceOrder implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @param CheckoutSession $checkoutSession
     * @param RequestFactory $requestFactory
     */
    public function __construct(CheckoutSession $checkoutSession, RequestFactory $requestFactory)
    {
        $this->checkoutSession = $checkoutSession;
        $this->requestFactory = $requestFactory;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $empty = "empty";
    }
}
