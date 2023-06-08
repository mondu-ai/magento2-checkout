<?php

namespace Mondu\Mondu\Ui\Component;

use Magento\Framework\UrlInterface;
use Magento\Framework\Url;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;

class OrderIdButton extends Column
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
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $backendUrl
     * @param string $viewUrl
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $backendUrl,
        $viewUrl = '',
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $backendUrl;
        $this->viewUrl    = $viewUrl;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $item[$this->getData('name')] = [
                    'adjust' => [
                        'href' => $this->urlBuilder->getUrl('sales/order/view', [
                            'order_id' => $item['order_id']
                        ]),
                        'label' => $item['order_id']
                    ]
                ];
            }
        }

        return $dataSource;
    }
}
