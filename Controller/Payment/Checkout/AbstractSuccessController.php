<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Checkout\Helper\Data as CheckoutData;
use Magento\Quote\Api\CartManagementInterface;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

abstract class AbstractSuccessController extends AbstractPaymentController
{
    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var CartManagementInterface
     */
    private $quoteManagement;

    /**
     * @var CheckoutData
     */
    private $checkoutData;

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param RedirectInterface $redirect
     * @param Session $checkoutSession
     * @param MessageManagerInterface $messageManager
     * @param MonduFileLogger $monduFileLogger
     * @param RequestFactory $requestFactory
     * @param JsonFactory $jsonResultFactory
     * @param \Magento\Customer\Model\Session $customerSession
     * @param OrderSender $orderSender
     * @param CheckoutData $checkoutData
     * @param CartManagementInterface $quoteManagement
     */
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response,
        RedirectInterface $redirect,
        Session $checkoutSession,
        MessageManagerInterface $messageManager,
        MonduFileLogger $monduFileLogger,
        RequestFactory $requestFactory,
        JsonFactory $jsonResultFactory,
        \Magento\Customer\Model\Session $customerSession,
        OrderSender $orderSender,
        CheckoutData $checkoutData,
        CartManagementInterface $quoteManagement,
        ABTesting $aBTesting
    ) {
        parent::__construct(
            $request,
            $response,
            $redirect,
            $checkoutSession,
            $messageManager,
            $monduFileLogger,
            $requestFactory,
            $jsonResultFactory,
            $aBTesting
        );
        $this->customerSession = $customerSession;
        $this->orderSender = $orderSender;

        $this->checkoutData = $checkoutData;
        $this->quoteManagement = $quoteManagement;
    }

    /**
     * Authorize Mondu Order
     *
     * @param string $monduId
     * @param string $referenceId
     * @return mixed
     * @throws LocalizedException
     * @throws \Exception
     */
    protected function authorizeMonduOrder($monduId, $referenceId)
    {
        $authorizeRequest = $this->requestFactory->create(RequestFactory::CONFIRM_ORDER);

        return $authorizeRequest->process(['orderUid' => $monduId, 'referenceId' => $referenceId]);
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @param Quote $quote
     * @return Quote
     */
    protected function prepareGuestQuote(Quote $quote)
    {
        $billingAddress = $quote->getBillingAddress();

        $email = $billingAddress->getOrigData('email') !== null
            ? $billingAddress->getOrigData('email') : $billingAddress->getEmail();

        $quote->setCustomerId(null)
            ->setCustomerEmail($email)
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);

        return $quote;
    }

    /**
     * Get magento checkut method
     *
     * @return string
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getCheckoutMethod()
    {
        $quote = $this->checkoutSession->getQuote();

        if ($this->customerSession->isLoggedIn()) {
            return \Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER;
        }
        if (!$quote->getCheckoutMethod()) {
            if ($this->checkoutData->isAllowedGuestCheckout($quote)) {
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
            } else {
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_REGISTER);
            }
        }
        return $quote->getCheckoutMethod();
    }

    /**
     * Place order in magento
     *
     * @param Quote $quote
     * @return mixed
     * @throws LocalizedException
     */
    protected function placeOrder($quote)
    {
        if ($this->getCheckoutMethod() == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote($quote);
        }

        $quote->collectTotals();

        return $this->quoteManagement->submit($quote);
    }

    /**
     * Get External reference id to be used
     *
     * @param Quote $quote
     * @return string
     * @throws \Exception
     */
    protected function getExternalReferenceId(Quote $quote)
    {
        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            $quote->reserveOrderId()->save();
            $reservedOrderId = $quote->getReservedOrderId();
        }
        return $reservedOrderId;
    }
}
