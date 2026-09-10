<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;

/**
 * Payment information shown for a Mondu order.
 *
 * Wired as the info block of every Mondu method so the net term reaches the
 * invoice PDF, the order view and the order emails through one path: Magento
 * renders this block into all three. The fields it may show are listed per
 * method as paymentInfoKeys in etc/config.xml.
 */
class Info extends ConfigurableInfo
{
    /**
     * GetLabel.
     *
     * @param string $field
     * @return Phrase
     */
    protected function getLabel($field)
    {
        if ($field === AsyncOrderFields::FIELD_NET_TERM) {
            return __('Payment term');
        }

        return __($field);
    }

    /**
     * Renders the stored net term as a period rather than a bare number.
     *
     * @param string $field
     * @param string $value
     * @return Phrase|string
     */
    protected function getValueView($field, $value)
    {
        if ($field === AsyncOrderFields::FIELD_NET_TERM) {
            return __('%1 days', $value);
        }

        return parent::getValueView($field, $value);
    }
}
