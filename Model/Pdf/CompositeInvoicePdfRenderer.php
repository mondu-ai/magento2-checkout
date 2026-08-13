<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Pdf;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Delegates rendering to the first available optional provider (e.g. Swissup),
 * falling back to the default core renderer. Any provider failure is logged and
 * degrades gracefully to the default renderer.
 */
class CompositeInvoicePdfRenderer implements InvoicePdfRendererInterface
{
    /**
     * @param InvoicePdfRendererInterface $defaultRenderer
     * @param LoggerInterface $logger
     * @param InvoicePdfProviderInterface[] $providers
     */
    public function __construct(
        private readonly InvoicePdfRendererInterface $defaultRenderer,
        private readonly LoggerInterface $logger,
        private readonly array $providers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(OrderInterface $order, InvoiceInterface $invoice): string
    {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof InvoicePdfProviderInterface) {
                continue;
            }

            try {
                if ($provider->isAvailable($order, $invoice)) {
                    return $provider->render($order, $invoice);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Mondu: invoice PDF provider failed, falling back to default renderer: '
                    . $e->getMessage()
                );
            }
        }

        return $this->defaultRenderer->render($order, $invoice);
    }
}
