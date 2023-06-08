<?php

namespace Mondu\Mondu\Block\Log\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * GetButtonData
     *
     * @return array
     */
    public function getButtonData()
    {
        return [
            'label' => __('Adjust'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}
