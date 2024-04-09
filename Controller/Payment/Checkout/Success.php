<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Model\Quote;

class Success extends AbstractSuccessController
{
    /**
     * @inheritDoc
     *
     * @throws NotFoundException
     */
    public function execute()
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
                $order->addStatusHistoryComment(__('Mondu: order id %1', $monduId));
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
        } catch (\Exception $e) {
            return $this->processException($e, 'Mondu: Error during the order process');
        }
    }
}
