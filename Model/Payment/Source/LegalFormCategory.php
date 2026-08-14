<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Values accepted by Mondu for `buyer.legal_form_category`.
 */
class LegalFormCategory implements OptionSourceInterface, ArgumentInterface
{
    public const OPTIONS = [
        'einzelunternehmen'                  => 'Einzelunternehmen',
        'kapital_und_personen_gesellschaft'  => 'Kapital- und Personengesellschaft',
        'offentliche_auftraggeber_einrichtung' => 'Öffentliche Auftraggeber / Einrichtung',
        'andere_gesellschaftsform'           => 'Andere Gesellschaftsform',
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
