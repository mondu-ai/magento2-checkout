<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Exception;
use Laminas\Http\Response as ResponseAlias;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Webapi\Response;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Log as MonduTransactions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Service\TransactionService;

class Token extends AbstractPaymentController
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
     * @param TransactionService $transactionService
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
        private readonly TransactionService $transactionService,
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
     * Creates a Mondu transaction and returns the checkout token response.
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $result = $this->jsonResultFactory->create();

        try {
            $userAgent = $this->request->getHeaders()->toArray()['User-Agent'] ?? null;
            $this->monduFileLogger->info('Token controller, trying to create the order');
            $paymentMethod = $this->request->getParam('payment_method') ?? null;

            $response = $this->transactionService->createTransaction([
                'email' => $this->request->getParam('email'),
                'user-agent' => $userAgent,
                'payment_method' => $paymentMethod,
            ]);

            return $result->setHttpResponseCode(Response::HTTP_OK)->setData($response);
        } catch (Exception $e) {
            return $result->setHttpResponseCode(ResponseAlias::STATUS_CODE_400)
                ->setData(['error' => true, 'message' => $e->getMessage()]);
        }
    }
}
