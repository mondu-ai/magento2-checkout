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
     * @return ResponseInterface
     */
    protected function redirect(string $path): ResponseInterface
    {
        $this->redirect->redirect($this->response, $path);
        return $this->response;
    }

    /**
     * Handles exception and redirects to cart with error message.
     *
     * @param Exception $e
     * @param string $message
     * @return ResponseInterface
     */
    protected function processException(Exception $e, string $message): ResponseInterface
    {
        $this->messageManager->addExceptionMessage($e, __($message));
        return $this->redirect('checkout/cart');
    }

    /**
     * Adds error message and redirects to cart.
     *
     * @param string $message
     * @return ResponseInterface
     */
    protected function redirectWithErrorMessage(string $message): ResponseInterface
    {
        $this->messageManager->addErrorMessage(__($message));
        return $this->redirect('checkout/cart');
    }
}
