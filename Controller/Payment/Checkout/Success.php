<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Exception;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;

class Success extends AbstractSuccessController
{
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
            $this->authorizeMonduOrder($monduId, $this->getExternalReferenceId($quote));

            $order = $this->placeOrder($quote);
            $this->checkoutSession->clearHelperData();
            $quoteId = $this->checkoutSession->getQuoteId();
            $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);

            if ($order) {
                $order->addCommentToStatusHistory(__('Mondu: order id %1', $monduId));
                $order->save();
                $this->checkoutSession->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());

                if (!$order->getEmailSent()) {
                    $this->orderSender->send($order);
                }
            }
            return $this->redirect('checkout/onepage/success/');
        } catch (LocalizedException $e) {
            return $this->processException($e, 'Mondu: An error occurred while trying to confirm the order');
        } catch (Exception $e) {
            return $this->processException($e, 'Mondu: Error during the order process');
        }
    }
}
