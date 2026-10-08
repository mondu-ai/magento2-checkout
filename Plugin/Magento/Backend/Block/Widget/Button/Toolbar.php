<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Backend\Block\Widget\Button;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as MageToolbar;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Mondu\Mondu\Helpers\CreditNote as CreditNoteHelper;

/**
 * Adds "Send credit note to Mondu" to the order view, where Magento cannot offer a credit memo.
 *
 * It is a fallback, not an alternative: wherever Magento is willing to create a credit memo the
 * button stays hidden and the standard process is the one to use, because that route books the
 * refund on both sides. See Helpers\CreditNote for exactly when it applies.
 */
class Toolbar
{
    /**
     * @param CreditNoteHelper $creditNoteHelper
     */
    public function __construct(
        private readonly CreditNoteHelper $creditNoteHelper,
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

        if (!$this->creditNoteHelper->isAvailableFor($order)) {
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
}
