<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Exception;
use Magento\Checkout\Helper\Data as CheckoutData;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Group as CustomerGroup;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Log as MonduTransactions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

abstract class AbstractSuccessController extends AbstractPaymentController
{
    /**
     * @param ABTesting $aBTesting
     * @param JsonFactory $jsonResultFactory
     * @param MessageManagerInterface $messageManager
     * @param MonduFileLogger $monduFileLogger
     * @param MonduTransactions $monduTransactions
     * @param RedirectInterface $redirect
     * @param RequestFactory $requestFactory
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param CheckoutSession $checkoutSession
     * @param CartManagementInterface $quoteManagement
     * @param CheckoutData $checkoutData
     * @param CustomerSession $customerSession
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     */
    public function __construct(
        ABTesting $aBTesting,
        JsonFactory $jsonResultFactory,
        MessageManagerInterface $messageManager,
        MonduFileLogger $monduFileLogger,
        MonduTransactions $monduTransactions,
        RedirectInterface $redirect,
        RequestFactory $requestFactory,
        RequestInterface $request,
        ResponseInterface $response,
        CheckoutSession $checkoutSession,
        protected CartManagementInterface $quoteManagement,
        protected CheckoutData $checkoutData,
        protected CustomerSession $customerSession,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderSender $orderSender,
    ) {
        parent::__construct(
            $aBTesting,
            $jsonResultFactory,
            $messageManager,
            $monduFileLogger,
            $monduTransactions,
            $redirect,
            $requestFactory,
            $request,
            $response,
            $checkoutSession
        );
    }

    /**
     * Authorize Mondu Order.
     *
     * @param string $monduId
     * @param string $referenceId
     * @throws LocalizedException
     * @throws Exception
     * @return mixed
     */
    protected function authorizeMonduOrder(string $monduId, string $referenceId)
    {
        $authorizeRequest = $this->requestFactory->create(RequestFactory::CONFIRM_ORDER);

        return $authorizeRequest->process(['orderUid' => $monduId, 'referenceId' => $referenceId]);
    }

    /**
     * Prepare quote for guest checkout order submit.
     *
     * @param CartInterface $quote
     * @return CartInterface
     */
    protected function prepareGuestQuote(CartInterface $quote): CartInterface
    {
        $billingAddress = $quote->getBillingAddress();
        $email = $billingAddress->getOrigData('email') ?? $billingAddress->getEmail();

        $quote->setCustomerId(null)
            ->setCustomerEmail($email)
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(CustomerGroup::NOT_LOGGED_IN_ID);

        return $quote;
    }

    /**
     * Get magento checkut method.
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return string
     */
    protected function getCheckoutMethod(): string
    {
        $quote = $this->checkoutSession->getQuote();

        if ($this->customerSession->isLoggedIn()) {
            return Onepage::METHOD_CUSTOMER;
        }
        if (!$quote->getCheckoutMethod()) {
            if ($this->checkoutData->isAllowedGuestCheckout($quote)) {
                $quote->setCheckoutMethod(Onepage::METHOD_GUEST);
            } else {
                $quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
            }
        }

        return $quote->getCheckoutMethod();
    }

    /**
     * Place order in magento.
     *
     * @param Quote $quote
     * @throws LocalizedException
     * @return mixed
     */
    protected function placeOrder(CartInterface $quote)
    {
        if ($this->getCheckoutMethod() === Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote($quote);
        }

        $quote->collectTotals();

        return $this->quoteManagement->submit($quote);
    }

    /**
     * Get External reference id to be used.
     *
     * @param CartInterface $quote
     * @throws Exception
     * @return string
     */
    protected function getExternalReferenceId(CartInterface $quote): string
    {
        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            $quote->reserveOrderId()->save();
            $reservedOrderId = $quote->getReservedOrderId();
        }

        return $reservedOrderId;
    }
}
