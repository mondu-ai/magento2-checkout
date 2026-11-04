<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Adminhtml\Order\CreditNote;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Throwable;

/**
 * Supplies the credit note form with the order and the invoices Mondu holds for it.
 */
class Form extends Template
{
    /**
     * @var OrderInterface|null
     */
    private $order;

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param MonduLogHelper $monduLogHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MonduLogHelper $monduLogHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The order the credit note is for.
     *
     * @return OrderInterface|null
     */
    public function getOrder(): ?OrderInterface
    {
        if ($this->order === null) {
            try {
                $this->order = $this->orderRepository->get((int) $this->getRequest()->getParam('order_id'));
            } catch (Throwable $e) {
                return null;
            }
        }

        return $this->order;
    }

    /**
     * Invoices Mondu holds for this order, as [magento invoice number => ['uuid', 'state', …]].
     *
     * These come from the Mondu log rather than from Magento's invoice collection: only the ones
     * actually registered at Mondu can carry a credit note, and only the log knows their uuid.
     *
     * @return array
     */
    public function getMonduInvoices(): array
    {
        $order = $this->getOrder();
        if (!$order || !$order->getMonduReferenceId()) {
            return [];
        }

        try {
            return $this->monduLogHelper->getMonduInvoiceMappings((string) $order->getMonduReferenceId());
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Where the form posts to.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl(
            'mondu/order_creditnote/save',
            ['order_id' => (int) $this->getRequest()->getParam('order_id')]
        );
    }

    /**
     * Back to the order the credit note is for.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl(
            'sales/order/view',
            ['order_id' => (int) $this->getRequest()->getParam('order_id')]
        );
    }

    /**
     * Order total, shown as the ceiling for the amount.
     *
     * @return string
     */
    public function getFormattedOrderTotal(): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }

        return (string) $order->getBaseCurrency()->formatTxt((float) $order->getBaseGrandTotal());
    }
}
