<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Order\CreditNote;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mondu\Mondu\Helpers\CreditNote as CreditNoteHelper;

/**
 * Form for sending a credit note to Mondu on its own, without a Magento credit memo.
 *
 * Magento only lets an order be credited for money it believes was collected, so a merchant who
 * keeps the invoice uncaptured until the money actually arrives cannot reach the credit memo
 * screen at all - while Mondu is perfectly ready to accept a credit note against the invoice it
 * already holds. Forcing Magento's screen open would leave its books with more refunded than
 * paid, so this offers the Mondu side of the operation separately and leaves Magento's own
 * accounting untouched.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * Issuing a credit note is the same authority as refunding an order.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::creditmemo';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param CreditNoteHelper $creditNoteHelper
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditNoteHelper $creditNoteHelper,
    ) {
        parent::__construct($context);
    }

    /**
     * Renders the credit note form for the requested order.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Mondu: this order could not be loaded.'));

            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        // Reachable by URL as well as by button, so the same rule is applied here: where Magento
        // can create a credit memo, that is the process to use.
        if (!$this->creditNoteHelper->isAvailableFor($order)) {
            $this->messageManager->addErrorMessage(
                __('Mondu: use the standard credit memo for this order.')
            );

            return $this->resultRedirectFactory->create()
                ->setPath('sales/order/view', ['order_id' => $orderId]);
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(
            __('Mondu credit note for order #%1', $order->getIncrementId())
        );

        return $resultPage;
    }
}
