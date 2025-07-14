<?php

declare(strict_types=1);

namespace Mondu\Mondu\Ui\DataProvider\Form;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Mondu\Mondu\Model\ResourceModel\Log\CollectionFactory as MonduLogCollectionFactory;

class LogDataProvider extends AbstractDataProvider
{
    protected array $loadedData;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param MonduLogCollectionFactory $monduLogCollectionFactory
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        protected MonduLogCollectionFactory $monduLogCollectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $this->monduLogCollectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Returns form data indexed by entity ID.
     *
     * @return array
     */
    public function getData(): array
    {
        if (isset($this->loadedData)) {
            return $this->loadedData;
        }

        $items = $this->collection->getItems();
        foreach ($items as $item) {
            $this->loadedData[$item->getId()] = $item->getData();
        }

        return $this->loadedData;
    }
}
