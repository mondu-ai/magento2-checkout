<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Throwable;

/**
 * Decides when the module may send a credit note to Mondu on its own.
 *
 * This is a fallback for one specific dead end, not a second way of refunding. Magento's own
 * credit memo is the process to use, and it stays the process to use wherever it works: it books
 * the refund on both sides, where sending only to Mondu leaves Magento with no record of it.
 *
 * The one case Magento cannot serve is an order whose invoice was never captured. Magento decides
 * from the money it believes was collected, so with total_paid empty it sees nothing to give back
 * and hides the button, while Mondu holds the invoice and will accept a credit note against it.
 * That is the gap, and only that.
 *
 * Because the rule is derived rather than configured, it needs no per-merchant switch and it
 * corrects itself: a merchant who starts capturing invoices gets Magento's own button back and
 * loses this one the same day, without anyone having to remember to turn anything off.
 */
class CreditNote
{
    /**
     * @param Log $monduLogHelper
     */
    public function __construct(
        private readonly Log $monduLogHelper,
    ) {
    }

    /**
     * Whether this order may be credited through Mondu directly.
     *
     * @param OrderInterface|null $order
     * @return bool
     */
    public function isAvailableFor(?OrderInterface $order): bool
    {
        if (!$order || !$order->getMonduReferenceId()) {
            return false;
        }

        return $this->isStandardCreditMemoOutOfReach($order)
            && $this->hasMonduInvoice((string) $order->getMonduReferenceId());
    }

    /**
     * Whether Magento refuses a credit memo only because nothing was ever captured.
     *
     * Every other refusal is left alone. An order on hold, in payment review, canceled or closed
     * is one Magento declines on its own terms, and Mondu has no business overriding that; an
     * order with nothing left to credit is simply finished.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isStandardCreditMemoOutOfReach(OrderInterface $order): bool
    {
        if (!$order instanceof SalesOrder) {
            return false;
        }

        // The standard route works here, so it is the one to use.
        if ($order->canCreditmemo()) {
            return false;
        }

        // PSR12 and the Magento sniff disagree about wrapping a condition across lines, so the
        // expression lives in a variable and the if stays on one line.
        $refusedOnItsOwnTerms = $order->canUnhold()
            || $order->isPaymentReview()
            || $order->isCanceled()
            || $order->getState() === SalesOrder::STATE_CLOSED;

        if ($refusedOnItsOwnTerms) {
            return false;
        }

        $creditable = (float) $order->getBaseGrandTotal() - (float) $order->getBaseTotalRefunded();

        return $creditable > 0.0001;
    }

    /**
     * Whether Mondu holds an invoice for this order that a credit note could be issued against.
     *
     * Deliberately not Log::canCreditMemo(): that reads mondu_state, which only advances once
     * Mondu has processed the invoice and which nothing pulls in until the next sync - there is
     * no order/shipped webhook - so right after shipping it still says confirmed and the button
     * would be missing exactly when it is wanted. Holding an invoice is the real precondition for
     * a credit note anyway, and it is recorded the moment Mondu accepts one. Mondu still refuses
     * a credit note it cannot accept, and that answer is shown to the admin.
     *
     * @param string $monduId
     * @return bool
     */
    public function hasMonduInvoice(string $monduId): bool
    {
        try {
            return $this->monduLogHelper->getMonduInvoiceMappings($monduId) !== [];
        } catch (Throwable $e) {
            // Callers render admin pages; hiding the option is the safe answer.
            return false;
        }
    }
}
