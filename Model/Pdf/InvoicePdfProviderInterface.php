<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Pdf;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Optional invoice PDF renderer that only applies when its backing 3rd-party
 * module is installed and enabled. The composite renderer asks isAvailable()
 * before delegating and falls back to the default renderer otherwise.
 */
interface InvoicePdfProviderInterface extends InvoicePdfRendererInterface
{
    /**
     * @param OrderInterface $order
     * @param InvoiceInterface $invoice
     * @return bool
     */
    public function isAvailable(OrderInterface $order, InvoiceInterface $invoice): bool;
}
