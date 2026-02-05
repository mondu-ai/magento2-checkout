<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Log as MonduTransactions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

abstract class AbstractPaymentController implements ActionInterface
{
    /**
     * Executes the controller action.
     *
     * @return ResponseInterface|ResultInterface
     */
    abstract public function execute(): ResponseInterface|ResultInterface;

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
     */
    public function __construct(
        protected ABTesting $aBTesting,
        protected JsonFactory $jsonResultFactory,
        protected MessageManagerInterface $messageManager,
        protected MonduFileLogger $monduFileLogger,
        protected MonduTransactions $monduTransactions,
        protected RedirectInterface $redirect,
        protected RequestFactory $requestFactory,
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected CheckoutSession $checkoutSession,
    ) {
    }

    /**
     * Redirects to the specified path.
     *
     * @param string $path
     * @param array $arguments
     * @return ResponseInterface
     */
    protected function redirect(string $path, array $arguments = []): ResponseInterface
    {
        $this->redirect->redirect($this->response, $path, $arguments);
        return $this->response;
    }

    /**
     * Redirects to checkout payment step.
     *
     * @param string|null $monduError Pass 'decline' or 'cancel' to show error via URL param (session may be lost)
     * @return ResponseInterface
     */
    protected function redirectToCheckoutPayment(?string $monduError = null): ResponseInterface
    {
        $arguments = ['_fragment' => 'payment'];
        if ($monduError !== null) {
            $arguments['_query'] = ['mondu_error' => $monduError];
        }
        return $this->redirect('checkout', $arguments);
    }

    /**
     * Handles exception and redirects back to checkout with error message.
     *
     * @param Exception $e
     * @param string $message
     * @return ResponseInterface
     */
    protected function processException(Exception $e, string $message): ResponseInterface
    {
        $this->messageManager->addExceptionMessage($e, __($message));
        return $this->redirectToCheckoutPayment();
    }

    /**
     * Adds error message and redirects back to checkout.
     *
     * @param string $message
     * @return ResponseInterface
     */
    protected function redirectWithErrorMessage(string $message, ?string $monduError = 'decline'): ResponseInterface
    {
        return $this->redirectToCheckoutPayment($monduError);
    }
}
