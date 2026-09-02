<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Order\CreditNote;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Throwable;

/**
 * Sends one credit note to Mondu for an invoice Mondu already holds.
 *
 * Nothing is written to Magento's own totals on purpose: this exists for merchants whose
 * invoices stay uncaptured, and inventing a payment for them so that a credit memo becomes
 * possible would leave the order refunded beyond what it was ever paid. What the operation did
 * is recorded in the order's comment history instead, so it is visible where an admin looks.
 */
class Save extends Action implements HttpPostActionInterface
{
    /**
     * Issuing a credit note is the same authority as refunding an order.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::creditmemo';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RequestFactory $requestFactory
     * @param MonduLogHelper $monduLogHelper
     * @param MonduFileLogger $monduFileLogger
     */
    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RequestFactory $requestFactory,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly MonduFileLogger $monduFileLogger,
    ) {
        parent::__construct($context);
    }

    /**
     * Validates the form, sends the credit note and reports the outcome on the order page.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $back = $this->resultRedirectFactory->create()
            ->setPath('sales/order/view', ['order_id' => $orderId]);

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(__('Mondu: this order could not be loaded.'));

            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $invoiceUid = (string) $this->getRequest()->getParam('invoice_uid');
        $amount = (float) str_replace(',', '.', (string) $this->getRequest()->getParam('amount'));
        $reference = trim((string) $this->getRequest()->getParam('external_reference_id'));

        $error = $this->validate($order, $invoiceUid, $amount);
        if ($error !== null) {
            $this->messageManager->addErrorMessage($error);

            return $this->resultRedirectFactory->create()
                ->setPath('mondu/order_creditnote/index', ['order_id' => $orderId]);
        }

        if ($reference === '') {
            $reference = $this->buildReference($order);
        }

        // Mondu works in minor units, and round() before the multiplication keeps a value like
        // 10.075 from turning into 1007 cents.
        $data = [
            'invoice_uid' => $invoiceUid,
            'gross_amount_cents' => (int) round(round($amount, 2) * 100),
            'external_reference_id' => $reference,
        ];

        $this->monduFileLogger->info('CreditNote controller: sending a credit note', [
            'orderNumber' => $order->getIncrementId(),
            'data' => $data,
        ]);

        try {
            $result = $this->requestFactory
                ->create(RequestFactory::MEMO, (int) $order->getStoreId())
                ->process($data);
        } catch (Throwable $e) {
            $this->monduFileLogger->info('CreditNote controller: request failed', [
                'orderNumber' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                __('Mondu: the credit note could not be sent. %1', $e->getMessage())
            );

            return $back;
        }

        if (isset($result['errors'])) {
            $details = $result['errors'][0]['details'] ?? 'unknown error';
            $name = $result['errors'][0]['name'] ?? 'unknown';
            $this->monduFileLogger->info('CreditNote controller: Mondu rejected the credit note', [
                'orderNumber' => $order->getIncrementId(),
                'errors' => $result['errors'],
            ]);
            $this->messageManager->addErrorMessage(__('Mondu: %1 - %2', $name, $details));

            return $back;
        }

        $uuid = $result['credit_note']['uuid'] ?? null;

        $order->addCommentToStatusHistory(
            __(
                'Mondu: credit note %1 sent for %2, reference %3.',
                $uuid ?: __('(no id returned)'),
                $order->getBaseCurrency()->formatTxt(round($amount, 2)),
                $reference
            )
        );
        $this->orderRepository->save($order);

        // Mondu moves the order on once a credit note lands, so pull the new state in rather
        // than leaving the log to drift until the next webhook.
        try {
            $this->monduLogHelper->syncOrder((string) $order->getMonduReferenceId());
        } catch (Throwable $e) {
            $this->monduFileLogger->info('CreditNote controller: could not sync the order after the credit note', [
                'orderNumber' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->messageManager->addSuccessMessage(__('Mondu: the credit note was sent.'));

        return $back;
    }

    /**
     * Returns the reason the credit note cannot be sent, or null when it can.
     *
     * @param OrderInterface $order
     * @param string $invoiceUid
     * @param float $amount
     * @return \Magento\Framework\Phrase|null
     */
    private function validate(OrderInterface $order, string $invoiceUid, float $amount)
    {
        if (!$order->getMonduReferenceId()) {
            return __('Mondu: this order was not placed with Mondu.');
        }

        if ($invoiceUid === '') {
            return __('Mondu: pick the invoice the credit note belongs to.');
        }

        if ($amount <= 0) {
            return __('Mondu: enter an amount greater than zero.');
        }

        // Mondu validates the amount against the invoice too, but catching it here keeps the
        // admin from waiting on a round trip for an obvious mistake.
        if (round($amount, 2) > round((float) $order->getBaseGrandTotal(), 2)) {
            return __(
                'Mondu: the amount cannot exceed the order total of %1.',
                $order->getBaseCurrency()->formatTxt((float) $order->getBaseGrandTotal())
            );
        }

        return null;
    }

    /**
     * Builds a reference that stays unique across repeated credit notes on one order.
     *
     * Mondu rejects a duplicate external_reference_id, and there is no Magento credit memo here
     * to borrow a number from, so the order number is suffixed by how many credit notes its
     * history already mentions.
     *
     * @param OrderInterface $order
     * @return string
     */
    private function buildReference(OrderInterface $order): string
    {
        $sent = 0;
        foreach ($order->getStatusHistoryCollection() as $history) {
            if (str_contains((string) $history->getComment(), 'Mondu: credit note')) {
                $sent++;
            }
        }

        return $order->getIncrementId() . '-CN' . ($sent + 1);
    }
}
