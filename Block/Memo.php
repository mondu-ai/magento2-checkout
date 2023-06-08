<?php
namespace Mondu\Mondu\Block;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Mondu\Mondu\Helpers\Log;

class Memo extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Mondu_Mondu::order/creditmemo/create/mondumemo.phtml';

    /**
     * @var Log
     */
    protected $monduLogger;

    /**
     * @var Registry
     */
    private $_coreRegistry;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param Log $logger
     */
    public function __construct(
        Context $context,
        Registry $registry,
        Log $logger
    ) {
        $this->_coreRegistry = $registry;
        $this->monduLogger = $logger;
        parent::__construct($context);
    }

    /**
     * GetCreditMemo
     *
     * @return mixed|null
     */
    public function getCreditMemo()
    {
        return $this->_coreRegistry->registry('current_creditmemo');
    }

    /**
     * Render
     *
     * @return string
     */
    public function render()
    {
        return $this->getOrderMonduId();
    }

    /**
     * GetOrder
     *
     * @return mixed
     */
    public function getOrder()
    {
        return $this->getCreditMemo()->getOrder();
    }

    /**
     * GetOrderMonduId
     *
     * @return string
     */
    public function getOrderMonduId()
    {
        $memo = $this->getCreditMemo();
        $order = $memo->getOrder();

        return $order->getMonduReferenceId();
    }

    /**
     * Invoice collection for specific order
     *
     * @return mixed
     */
    public function invoices()
    {
        $invoiceCollection = $this->getOrder()->getInvoiceCollection();
        return $invoiceCollection;
    }

    /**
     * GetInvoiceMappings
     *
     * @return array|mixed
     */
    public function getInvoiceMappings()
    {
        $monduId = $this->getOrderMonduId();
        $log = $this->monduLogger->getTransactionByOrderUid($monduId);

        if (!$log) {
            return [];
        }

        return $log['addons'] ? (json_decode($log['addons'], true) ?? []) : [];
    }
}
