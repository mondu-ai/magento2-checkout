<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\ProductMetadataInterface;

class ModuleHelper {
    const MODULE_NAME = 'Mondu_Mondu';
    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    public function __construct(
        ModuleListInterface $moduleList,
        ProductMetadataInterface $productMetadata
    ) {
        $this->moduleList = $moduleList;
        $this->productMetadata = $productMetadata;
    }

    public function getEnvironmentInformation(): array
    {
        return [
            'plugin' => $this->getModuleNameForApi(),
            'version' => $this->getModuleVersion(),
            'language_version' => 'PHP '. phpversion(),
            'shop_version' => $this->productMetadata->getVersion(),
        ];
    }

    public function getModuleVersion(): string
    {
        return $this->moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function getModuleNameForApi(): string
    {
        return 'MAGENTO2';
    }
}
