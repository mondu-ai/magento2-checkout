<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class Installments implements OptionSourceInterface, ArgumentInterface
{
    public const OPTIONS = [3, 6, 12];

    public function toOptionArray(): array
    {
        $out = [];
        foreach (self::OPTIONS as $n) {
            $out[] = ['value' => $n, 'label' => (string) $n];
        }
        return $out;
    }
}
