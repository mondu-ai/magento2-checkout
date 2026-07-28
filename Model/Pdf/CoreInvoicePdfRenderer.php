<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Pdf;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Pdf\Invoice as PdfInvoiceModel;

/**
 * Default renderer using Magento's core invoice PDF model. Store-scope/theme
 * emulation is handled by the caller (Controller\Index\Invoice).
 */
class CoreInvoicePdfRenderer implements InvoicePdfRendererInterface
{
    /**
     * @param PdfInvoiceModel $pdfInvoiceModel
     */
    public function __construct(
        private readonly PdfInvoiceModel $pdfInvoiceModel
    ) {
    }

    /**
     * @inheritDoc
     * @throws \Zend_Pdf_Exception
     */
    public function render(OrderInterface $order, InvoiceInterface $invoice): string
    {
        return $this->pdfInvoiceModel->getPdf([$invoice])->render();
    }
}
