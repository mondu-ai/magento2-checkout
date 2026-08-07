<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Pdf;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Renders the invoice PDF through Swissup PDF Invoice (swissup/module-pdf-invoice)
 * when that module is installed and enabled.
 *
 * Swissup does not extend Magento\Sales\Model\Order\Pdf\Invoice: it plugs into the
 * print controllers and renders with its own mpdf engine (Swissup\PdfInvoice\Model\Pdf),
 * so calling the core model directly bypasses the merchant's template. This adapter
 * invokes Swissup's own model to produce the same PDF the merchant sees.
 *
 * Swissup classes are referenced only by string / class_exists and instantiated via
 * the ObjectManager, so the Mondu module keeps no hard (composer) dependency on Swissup.
 */
class SwissupInvoicePdfRenderer implements InvoicePdfProviderInterface
{
    private const PDF_MODEL = 'Swissup\\PdfInvoice\\Model\\Pdf';
    private const HELPER = 'Swissup\\PdfInvoice\\Helper\\Data';
    private const TEMPLATE_TYPE_INVOICE = 'invoice';
    private const OUTPUT_STRING_RETURN = 'S';

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(OrderInterface $order, InvoiceInterface $invoice): bool
    {
        if (!class_exists(self::PDF_MODEL) || !class_exists(self::HELPER)) {
            return false;
        }

        try {
            $helper = $this->objectManager->get(self::HELPER);
            if (method_exists($helper, 'isEnabled') && !$helper->isEnabled()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function render(OrderInterface $order, InvoiceInterface $invoice): string
    {
        $pdf = $this->objectManager->create(self::PDF_MODEL);
        $pdf->setTemplateType(self::TEMPLATE_TYPE_INVOICE);
        $pdf->setEntityId($invoice->getId());

        $content = $pdf->download(self::OUTPUT_STRING_RETURN);

        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('Swissup PDF Invoice returned empty content');
        }

        return $content;
    }
}
