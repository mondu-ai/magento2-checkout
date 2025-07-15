<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;

class ModuleHelper
{
    public const MODULE_NAME = 'Mondu_Mondu';

    /**
     * @param ModuleListInterface $moduleList
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ProductMetadataInterface $productMetadata,
    ) {
    }

    /**
     * Returns environment data.
     *
     * @return array
     */
    public function getEnvironmentInformation(): array
    {
        return [
            'plugin' => $this->getModuleNameForApi(),
            'version' => $this->getModuleVersion(),
            'language_version' => 'PHP ' . PHP_VERSION,
            'shop_version' => $this->productMetadata->getVersion(),
        ];
    }

    /**
     * Returns the Mondu module version.
     *
     * @return string
     */
    public function getModuleVersion(): string
    {
        return $this->moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    /**
     * Returns the Mondu integration name for API identification.
     *
     * @return string
     */
    public function getModuleNameForApi(): string
    {
        return 'magento2';
    }
}
