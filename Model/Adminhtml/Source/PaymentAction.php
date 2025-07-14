<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PaymentAction implements OptionSourceInterface
{
    /**
     * Returns an array of available payment actions.
     *
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'authorize',
                'label' => __('Authorize'),
            ],
        ];
    }
}
