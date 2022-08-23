<?php

namespace Mondu\Mondu\Model\Adminhtml\Source;
use Magento\Payment\Model\MethodInterface;

class PaymentAction implements \Magento\Framework\Data\OptionSourceInterface
{

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'authorize',
                'label' => __('Authorize')
            ]
        ];
    }
}
