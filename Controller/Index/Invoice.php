<?php
namespace Mondu\Mondu\Controller\Index;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Sales\Model\Order\Pdf\Invoice as PdfInvoiceModel;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zend_Pdf_Exception;

class Invoice implements ActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var PdfInvoiceModel
     */
    private $_pdfInvoiceModel;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var RawFactory
     */
    private $resultRawFactory;

    /**
     * @param RequestInterface $request
     * @param PdfInvoiceModel $pdfInvoiceModel
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RawFactory $resultRawFactory
     */
    public function __construct(
        RequestInterface $request,
        PdfInvoiceModel $pdfInvoiceModel,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RawFactory $resultRawFactory
    ) {
        $this->request = $request;
        $this->_pdfInvoiceModel = $pdfInvoiceModel;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resultRawFactory = $resultRawFactory;
    }

    /**
     * GetRequest
     *
     * @return RequestInterface
     */
    private function getRequest()
    {
        return $this->request;
    }

    /**
     * Execute
     *
     * @return Raw
     * @throws NotFoundException
     * @throws Zend_Pdf_Exception
     */
    public function execute()
    {
        $orderIdentifierMondu = $this->getRequest()->getParam('id');
        $invoiceReference = $this->getRequest()->getParam('r');

        if (!$orderIdentifierMondu || !$invoiceReference) {
            throw new NotFoundException(__('Not found'));
        }

        $searchCriteria = $this->searchCriteriaBuilder->addFilter(
            'mondu_reference_id',
            $orderIdentifierMondu
        )->create();

        $result = $this->orderRepository->getList($searchCriteria);
        $orders = $result->getItems();

        if (empty($orders)) {
            throw new NotFoundException(__('Not found'));
        }

        $order = end($orders);
        $invoice = null;
        foreach ($order->getInvoiceCollection() as $i) {
            if ($i->getIncrementId() === $invoiceReference) {
                $invoice = $i;
            }
        }
        if (!$invoice) {
            throw new NotFoundException(__('Not found'));
        }
        $pdfContent = $this->_pdfInvoiceModel->getPdf([$invoice])->render();

        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHeader('Content-type', 'application/pdf');
        $resultRaw->setHeader('Content-Disposition', 'attachment; filename=invoice.pdf');
        $resultRaw->setContents($pdfContent);

        return $resultRaw;
    }
}
