<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;

class Memo extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Mondu_Mondu::order/creditmemo/create/mondumemo.phtml';

    /**
     * @param Context $context
     * @param MonduLogHelper $monduLogHelper
     * @param Registry $registry
     * @param SerializerInterface $serializer
     */
    public function __construct(
        Context $context,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly Registry $registry,
        private readonly SerializerInterface $serializer,
    ) {
        parent::__construct($context);
    }

    /**
     * Returns the current credit memo from the registry.
     *
     * @return mixed|null
     */
    public function getCreditMemo()
    {
        return $this->registry->registry('current_creditmemo');
    }

    /**
     * Returns the Mondu reference ID of the order.
     *
     * @return string
     */
    public function render()
    {
        return $this->getOrderMonduId();
    }

    /**
     * Returns the order associated with the current credit memo.
     *
     * @return mixed
     */
    public function getOrder()
    {
        return $this->getCreditMemo()->getOrder();
    }

    /**
     * Returns Mondu reference ID of the order.
     *
     * @return string
     */
    public function getOrderMonduId(): string
    {
        $memo = $this->getCreditMemo();
        $order = $memo->getOrder();

        return $order->getMonduReferenceId();
    }

    /**
     * Returns invoice collection for the order.
     *
     * @return mixed
     */
    public function invoices()
    {
        return $this->getOrder()->getInvoiceCollection();
    }

    /**
     * Returns invoice mapping data from Mondu log addons.
     *
     * @throws LocalizedException
     * @return array
     */
    public function getInvoiceMappings(): array
    {
        $monduId = $this->getOrderMonduId();
        $log = $this->monduLogHelper->getTransactionByOrderUid($monduId);

        if (!$log) {
            return [];
        }

        return $log['addons'] ? ($this->serializer->unserialize($log['addons']) ?? []) : [];
    }
}
