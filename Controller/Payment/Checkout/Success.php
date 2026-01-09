<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Exception;
use Magento\Checkout\Helper\Data as CheckoutData;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\HeadersHelper;
use Mondu\Mondu\Helpers\Log as MonduTransactions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Success extends AbstractSuccessController
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
     * @param CartRepositoryInterface $cartRepository
     * @param CheckoutData $checkoutData
     * @param CustomerSession $customerSession
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param UrlBuilder $urlBuilder
     * @param HeadersHelper $headersHelper
     * @param Curl $curl
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
        CartManagementInterface $quoteManagement,
        CartRepositoryInterface $cartRepository,
        CheckoutData $checkoutData,
        CustomerSession $customerSession,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        private readonly UrlBuilder $urlBuilder,
        private readonly HeadersHelper $headersHelper,
        private readonly Curl $curl,
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
            $checkoutSession,
            $quoteManagement,
            $cartRepository,
            $checkoutData,
            $customerSession,
            $orderRepository,
            $orderSender
        );
    }

    /**
     * Confirms Mondu order and redirects to the success page.
     *
     * @throws NotFoundException
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $monduId = $this->request->getParam('order_uuid');

        if (!$monduId) {
            throw new NotFoundException(__('Not found'));
        }

        try {
            $monduTransaction = $this->monduTransactions->getTransactionByOrderUid($monduId);

            if ($monduTransaction && $monduTransaction['is_confirmed']) {
                $order = $this->orderRepository->get($monduTransaction['order_id']);
                $this->checkoutSession->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus())
                    ->setLastSuccessQuoteId($order->getQuoteId())
                    ->setLastQuoteId($order->getQuoteId());

                return $this->redirect('checkout/onepage/success/');
            }

            $quote = $this->checkoutSession->getQuote();
            $externalReferenceId = $this->getExternalReferenceId($quote);
            $this->authorizeMonduOrder($monduId, $externalReferenceId);

            // Sync payment method from Mondu (buyer may have changed it in hosted checkout)
            $this->syncPaymentMethodFromMondu($monduId, $quote);

            $order = $this->placeOrder($quote);
            $this->checkoutSession->clearHelperData();
            $quoteId = $this->checkoutSession->getQuoteId();
            $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);

            if ($order) {
                $order->addCommentToStatusHistory(__('Mondu: order id %1', $monduId));
                $this->orderRepository->save($order);
                $this->checkoutSession->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());

                if (!$order->getEmailSent()) {
                    $this->orderSender->send($order);
                }
            }

            return $this->redirect('checkout/onepage/success/');
        } catch (LocalizedException $e) {
            $this->monduFileLogger->error('Error in Success::execute()', [
                'mondu_id' => $monduId,
                'message' => $e->getMessage()
            ]);
            return $this->processException($e, 'Mondu: An error occurred while trying to confirm the order');
        } catch (Exception $e) {
            $this->monduFileLogger->error('Error in Success::execute()', [
                'mondu_id' => $monduId,
                'message' => $e->getMessage()
            ]);
            return $this->processException($e, 'Mondu: Error during the order process');
        }
    }

    /**
     * Fetches Mondu order data and syncs the payment method to quote if different.
     *
     * @param string $monduId
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return void
     */
    private function syncPaymentMethodFromMondu(string $monduId, $quote): void
    {
        try {
            $monduOrder = $this->fetchMonduOrder($monduId);

            if (!isset($monduOrder['payment_method'])) {
                return;
            }

            $monduPaymentMethod = $monduOrder['payment_method'];
            $magentoPaymentCode = PaymentMethodHelper::MAPPING[$monduPaymentMethod] ?? null;

            if (!$magentoPaymentCode) {
                return;
            }

            // Update quote payment method if different from what buyer selected in Mondu checkout
            $currentPaymentMethod = $quote->getPayment()->getMethod();
            if ($currentPaymentMethod !== $magentoPaymentCode) {
                $quote->getPayment()->setMethod($magentoPaymentCode);
                $this->cartRepository->save($quote);
            }
        } catch (Exception $e) {
            $this->monduFileLogger->error('Failed to sync payment method from Mondu', [
                'mondu_id' => $monduId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Fetches order data from Mondu API.
     *
     * @param string $monduId
     * @return array
     * @throws Exception
     */
    private function fetchMonduOrder(string $monduId): array
    {
        $url = $this->urlBuilder->getOrderUrl($monduId);
        $headers = $this->headersHelper->getHeaders();

        $this->curl->setHeaders($headers);
        $this->curl->get($url);

        $httpStatus = $this->curl->getStatus();
        $response = $this->curl->getBody();

        if ($httpStatus !== 200) {
            $this->monduFileLogger->error('Failed to fetch Mondu order', [
                'mondu_id' => $monduId,
                'http_status' => $httpStatus
            ]);
            return [];
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $result['order'] ?? [];
    }
}
