<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Pdf;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Renders an invoice into PDF bytes for the invoice_url Mondu fetches.
 *
 * Introduced so the renderer is pluggable: 3rd-party PDF template modules that
 * do not extend Magento's core PDF model (and use their own rendering engine,
 * e.g. Swissup PDF Invoice) can provide their own implementation instead of
 * being bypassed by a hard-coded core-model call.
 */
interface InvoicePdfRendererInterface
{
    /**
     * @param OrderInterface $order
     * @param InvoiceInterface $invoice
     * @return string Raw PDF content
     */
    public function render(OrderInterface $order, InvoiceInterface $invoice): string;
}
