<?php
namespace Mondu\Mondu\Model;

use Mondu\Mondu\Model\LogFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Filesystem;

/**
 * Class DataProvider
 */
class DataProvider extends \Magento\Ui\DataProvider\AbstractDataProvider
{
    /**
     * @var \PHPCuong\BannerSlider\Model\ResourceModel\Banner\Collection
     */
    protected $collection;

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var array
     */
    protected $loadedData;

    /**
     * @var Filesystem
     */
    private $fileInfo;

    /**
     * Constructor
     *
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $bannerCollectionFactory
     * @param DataPersistorInterface $dataPersistor
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        LogFactory $logCollectionFactory,
        DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $logCollectionFactory->create()->getCollection();
        $this->dataPersistor = $dataPersistor;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Get data
     *
     * @return array
     */
    public function getData()
    {
        if (isset($this->loadedData)) {
            return $this->loadedData;
        }
        $items = $this->collection->getItems();
        foreach ($items as $banner) {
            $this->loadedData[$banner->getId()] = $banner->getData();
        }

        // Used from the Save action
//        $data = $this->dataPersistor->get('banners_slider');
//        if (!empty($data)) {
//            $banner = $this->collection->getNewEmptyItem();
//            $banner->setData($data);
//            $this->loadedData[$banner->getId()] = $banner->getData();
//            $this->dataPersistor->clear('banners_slider');
//        }
//        $this->loadedData[38]['net_price_cents'] = 300000;
//        $this->loadedData[38]['tax_cents'] = 300000;

        return $this->loadedData;
    }
}