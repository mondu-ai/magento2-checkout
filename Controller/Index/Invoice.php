<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Index;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Mondu\Mondu\Model\Pdf\InvoicePdfRendererInterface;
use Zend_Pdf_Exception;

class Invoice implements ActionInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoicePdfRendererInterface $pdfRenderer
     * @param RawFactory $resultRawFactory
     * @param RequestInterface $request
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param AppEmulation $appEmulation
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoicePdfRendererInterface $pdfRenderer,
        private readonly RawFactory $resultRawFactory,
        private readonly RequestInterface $request,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AppEmulation $appEmulation,
    ) {
    }

    /**
     * Generates and returns the PDF content for a Mondu invoice by its reference ID.
     *
     * @throws NotFoundException
     * @throws Zend_Pdf_Exception
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $orderIdentifierMondu = $this->request->getParam('id');
        $invoiceReference = $this->request->getParam('r');
        if (!$orderIdentifierMondu || !$invoiceReference) {
            throw new NotFoundException(__('Not found'));
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('mondu_reference_id', $orderIdentifierMondu)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        if (empty($orders)) {
            throw new NotFoundException(__('Not found'));
        }

        /** @var OrderInterface $order */
        $order = end($orders);
        $invoice = $this->getInvoiceByIncrementId($order, $invoiceReference);
        if (!$invoice) {
            throw new NotFoundException(__('Not found'));
        }

        $pdfContent = $this->renderInvoicePdf($order, $invoice);

        return $this->resultRawFactory->create()
            ->setHeader('Content-type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename=invoice.pdf')
            ->setContents($pdfContent);
    }

    /**
     * Renders the invoice PDF under the order's store front-end environment.
     *
     * Emulating the order's store view loads that store's configuration and
     * design/theme, so merchant-specific invoice template customizations are
     * applied instead of Magento's default layout. Mondu fetches this URL
     * server-side without a session, so without emulation the default store
     * scope is used and custom templates are skipped.
     *
     * The actual rendering is delegated to the configured
     * {@see InvoicePdfRendererInterface}: by default Magento's core PDF model,
     * or a 3rd-party engine (e.g. Swissup PDF Invoice) when its provider reports
     * itself available. This ensures the PDF sent to Mondu matches the invoice
     * the merchant actually produces, even when the PDF module bypasses the core
     * model and uses its own rendering engine.
     *
     * @param OrderInterface $order
     * @param InvoiceInterface $invoice
     * @throws Zend_Pdf_Exception
     * @return string
     */
    private function renderInvoicePdf(OrderInterface $order, InvoiceInterface $invoice): string
    {
        $this->appEmulation->startEnvironmentEmulation(
            (int) $order->getStoreId(),
            Area::AREA_FRONTEND,
            true
        );

        try {
            return $this->pdfRenderer->render($order, $invoice);
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Retrieves the invoice from an order by increment ID.
     *
     * @param OrderInterface $order
     * @param string $invoiceRef
     * @return InvoiceInterface|null
     */
    private function getInvoiceByIncrementId(OrderInterface $order, string $invoiceRef): ?InvoiceInterface
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->getIncrementId() === $invoiceRef) {
                return $invoice;
            }
        }

        return null;
    }
}
