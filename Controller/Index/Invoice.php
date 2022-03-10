<?php
namespace Mondu\Mondu\Controller\Index;

use \Magento\Framework\App\RequestInterface;
use \Magento\Framework\Exception\NotFoundException;

class Invoice implements \Magento\Framework\App\ActionInterface {
    private $request;

    public function __construct(
        RequestInterface $request,
        \Magento\Sales\Model\Order\Pdf\Invoice $pdfInvoiceModel,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
    ) {
        $this->request = $request;
        $this->_pdfInvoiceModel = $pdfInvoiceModel;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resultRawFactory = $resultRawFactory;
    }

    private function getRequest() {
        return $this->request;
    }

    public function execute() {
        $orderIdentifierMondu = $this->getRequest()->getParam('id');
        $invoiceReference = $this->getRequest()->getParam('r');

        if(!$orderIdentifierMondu || !$invoiceReference) {
            throw new NotFoundException(__('invoice not found'));
        }

        $searchCriteria = $this->searchCriteriaBuilder->addFilter(
            'mondu_reference_id',
            $orderIdentifierMondu
        )->create();

        $result = $this->orderRepository->getList($searchCriteria);
        $orders = $result->getItems();

        if(empty($orders)) {
            throw new NotFoundException(__('invoice not found'));
        }

        $order = reset($orders);
        $invoice = null;
        foreach($order->getInvoiceCollection() as $i) {
            if($i->getIncrementId() === $invoiceReference) {
                $invoice = $i;
            }
        }
        if(!$invoice) {
            throw new NotFoundException(__('invoice not found'));
        }
        $pdfContent = $this->_pdfInvoiceModel->getPdf([$invoice])->render();

        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHeader('Content-type', 'application/pdf');
        $resultRaw->setHeader('Content-Disposition', 'attachment; filename=invoice.pdf');
        $resultRaw->setContents($pdfContent);

        return $resultRaw;
    }
}
