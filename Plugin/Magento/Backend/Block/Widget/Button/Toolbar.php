<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Backend\Block\Widget\Button;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as MageToolbar;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Throwable;

/**
 * Adds "Send credit note to Mondu" to the order view.
 *
 * Magento's own Credit Memo button is driven by Order::canCreditmemo(), which comes down to
 * whether money was captured. Merchants who leave the Magento invoice uncaptured until the money
 * arrives therefore never see it, and cannot credit anything - even though Mondu holds the
 * invoice and will accept a credit note against it. This is the Mondu-side operation on its own,
 * shown whenever Mondu says the order can be credited.
 *
 * It does not replace the Magento button and never hides it: when Magento is willing to create a
 * credit memo, that route stays the better one, because it also books the refund in Magento.
 */
class Toolbar
{
    /**
     * @param MonduLogHelper $monduLogHelper
     */
    public function __construct(
        private readonly MonduLogHelper $monduLogHelper,
    ) {
    }

    /**
     * Adds the credit note button to the order view toolbar.
     *
     * @param MageToolbar $subject
     * @param AbstractBlock $context
     * @param ButtonList $buttonList
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePushButtons(
        MageToolbar $subject,
        AbstractBlock $context,
        ButtonList $buttonList
    ): array {
        if (!$context instanceof OrderView) {
            return [$context, $buttonList];
        }

        $order = $context->getOrder();
        $monduId = $order ? $order->getMonduReferenceId() : null;

        if (!$monduId || !$this->hasMonduInvoice((string) $monduId)) {
            return [$context, $buttonList];
        }

        $buttonList->add(
            'mondu_credit_note',
            [
                'label' => __('Send credit note to Mondu'),
                'class' => 'mondu-credit-note',
                'onclick' => sprintf(
                    "setLocation('%s')",
                    $context->getUrl('mondu/order_creditnote/index', ['order_id' => $order->getEntityId()])
                ),
            ]
        );

        return [$context, $buttonList];
    }

    /**
     * Whether Mondu holds an invoice for this order that a credit note could be issued against.
     *
     * Deliberately not Log::canCreditMemo(): that reads mondu_state, which only advances once
     * Mondu has processed the invoice and which nothing pulls in until the next sync - there is
     * no order/shipped webhook - so right after shipping it still says confirmed and the button
     * would be missing exactly when it is wanted. Holding an invoice is the real precondition for
     * a credit note anyway, and it is recorded the moment Mondu accepts one. Mondu still refuses
     * a credit note it cannot accept, and that answer is shown to the admin.
     *
     * @param string $monduId
     * @return bool
     */
    private function hasMonduInvoice(string $monduId): bool
    {
        try {
            return $this->monduLogHelper->getMonduInvoiceMappings($monduId) !== [];
        } catch (Throwable $e) {
            // The order page has to render regardless; hiding the button is the safe answer.
            return false;
        }
    }
}
