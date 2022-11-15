<?php

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Module\ModuleListInterface;

class ModuleHelper {
    const MODULE_NAME = 'Mondu_Mondu';
    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    public function __construct(
        ModuleListInterface $moduleList
    ) {
        $this->moduleList = $moduleList;
    }

    public function getEnvironmentInformation(): array
    {
        return [
            'plugin_language' => 'PHP',
            'plugin_name' => $this->getModuleNameForApi(),
            'plugin_version' => $this->getModuleVersion(),
            'plugin_language_version' => phpversion(),
            'shop_version' => '2.2.3',
        ];
    }

    public function getModuleVersion(): string
    {
        return $this->moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function getModuleNameForApi(): string
    {
        return 'mondu-magento2';
    }
}
