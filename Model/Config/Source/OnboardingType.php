<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OnboardingType implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'hosted_onboarding', 'label' => __('Hosted Onboarding')],
            ['value' => 'trade_account', 'label' => __('Digital Trade Account (DTA)')],
        ];
    }
}
