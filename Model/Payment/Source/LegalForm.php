<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class LegalForm implements OptionSourceInterface, ArgumentInterface
{
    public const OPTIONS = [
        'GmbH'         => 'GmbH',
        'AG'           => 'AG',
        'UG'           => 'UG (haftungsbeschränkt)',
        'OHG'          => 'OHG',
        'KG'           => 'KG',
        'GmbH & Co. KG' => 'GmbH & Co. KG',
        'GbR'          => 'GbR',
        'sole_trader'  => 'Sole trader / Einzelunternehmer',
        'freelancer'   => 'Freelancer / Freiberufler',
        'e.K.'         => 'e.K.',
        'e.V.'         => 'e.V.',
        'other'        => 'Other',
    ];

    public function toOptionArray(): array
    {
        $out = [];
        foreach (self::OPTIONS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }
}
