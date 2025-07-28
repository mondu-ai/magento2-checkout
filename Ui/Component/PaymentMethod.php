<?php

declare(strict_types=1);

namespace Mondu\Mondu\Ui\Component;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;

class PaymentMethod extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly PaymentMethodHelper $paymentMethodHelper,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Replaces raw payment method codes with readable labels in the listing grid.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $item[$this->getData('name')] = $this->paymentMethodHelper->getLabel($item['payment_method']);
            }
        }

        return $dataSource;
    }
}
