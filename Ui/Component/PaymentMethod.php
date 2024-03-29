<?php
namespace Mondu\Mondu\Ui\Component;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;

class PaymentMethod extends Column
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var mixed|string
     */
    private $viewUrl;
    /**
     * @var \Mondu\Mondu\Helpers\PaymentMethod
     */
    private $paymentMethodHelper;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $backendUrl
     * @param \Mondu\Mondu\Helpers\PaymentMethod $paymentMethodHelper
     * @param string $viewUrl
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $backendUrl,
        \Mondu\Mondu\Helpers\PaymentMethod $paymentMethodHelper,
        $viewUrl = '',
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $backendUrl;
        $this->viewUrl    = $viewUrl;
        $this->paymentMethodHelper = $paymentMethodHelper;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
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
