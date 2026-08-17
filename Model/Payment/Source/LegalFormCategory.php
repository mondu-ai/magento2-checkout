<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Values accepted by Mondu for `buyer.legal_form_category`.
 *
 * The API only takes the German identifiers, so those stay as the option values
 * and are what we send; the labels are translatable and show up in the admin's
 * own language (see i18n/*.csv).
 */
class LegalFormCategory implements OptionSourceInterface, ArgumentInterface
{
    public const OPTIONS = [
        'einzelunternehmen'                    => 'Sole trader',
        'kapital_und_personen_gesellschaft'    => 'Corporation or partnership',
        'offentliche_auftraggeber_einrichtung' => 'Public authority or institution',
        'andere_gesellschaftsform'             => 'Other legal form',
    ];

    public function toOptionArray(): array
    {
        $out = [];
        foreach (self::OPTIONS as $value => $label) {
            $out[] = ['value' => $value, 'label' => __($label)];
        }
        return $out;
    }
}
