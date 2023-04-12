<?php
namespace Mondu\Mondu\Block;

class Memo extends \Magento\Framework\View\Element\Template
{
    protected $_template = 'Mondu_Mondu::order/creditmemo/create/mondumemo.phtml';
    protected $_monduLogger;

    public function __construct(\Magento\Framework\View\Element\Template\Context $context, \Magento\Framework\Registry $registry, \Mondu\Mondu\Helpers\Log $logger)
    {
        $this->_coreRegistry = $registry;
        $this->_monduLogger = $logger;
        parent::__construct($context);
    }

    public function getCreditMemo()
    {
        return $this->_coreRegistry->registry('current_creditmemo');
    }

    public function render()
    {
        return $this->getOrderMonduId();
    }

    public function getOrder()
    {
        return $this->getCreditMemo()->getOrder();
    }

    public function getOrderMonduId()
    {
        $memo = $this->getCreditMemo();
        $order = $memo->getOrder();

        return $order->getMonduReferenceId();
    }

    public function invoices()
    {
        $invoiceCollection = $this->getOrder()->getInvoiceCollection();
        return $invoiceCollection;
    }

    public function getInvoiceMappings()
    {
        $monduId = $this->getOrderMonduId();
        $log = $this->_monduLogger->getTransactionByOrderUid($monduId);

        if (!$log) {
            return [];
        }

        return $log['addons'] ? (json_decode($log['addons'], true) ?? []) : [];
    }
}
