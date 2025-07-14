<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Index;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\Invoice as PdfInvoiceModel;
use Zend_Pdf_Exception;

class Invoice implements ActionInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param PdfInvoiceModel $pdfInvoiceModel
     * @param RawFactory $resultRawFactory
     * @param RequestInterface $request
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PdfInvoiceModel $pdfInvoiceModel,
        private readonly RawFactory $resultRawFactory,
        private readonly RequestInterface $request,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
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

        $pdfContent = $this->pdfInvoiceModel->getPdf([$invoice])->render();

        return $this->resultRawFactory->create()
            ->setHeader('Content-type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename=invoice.pdf')
            ->setContents($pdfContent);
    }

    /**
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
