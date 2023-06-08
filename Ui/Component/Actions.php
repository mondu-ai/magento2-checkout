<?php

namespace Mondu\Mondu\Ui\Component;

use Magento\Framework\UrlInterface;
use Magento\Framework\Url;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;

class Actions extends Column
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
                // here we can also use the data from $item to configure some parameters of an action URL
                $item[$this->getData('name')] = [
                    'adjust' => [
                        'href' => $this->urlBuilder->getUrl('mondu/log/adjust', [
                            'entity_id' => $item['entity_id']
                        ]),
                        'label' => __('Adjust')
                    ]
                ];
            }
        }

        return $dataSource;
    }
}
