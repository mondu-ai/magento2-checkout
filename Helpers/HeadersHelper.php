<?php

namespace Mondu\Mondu\Helpers;

use Mondu\Mondu\Model\Ui\ConfigProvider;

class HeadersHelper {
    /**
     * @var ModuleHelper
     */
    private $moduleHelper;

    /**
     * @var ConfigProvider
     */
    private $configProvider;
    /**
     * @param ModuleHelper $moduleHelper
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        ModuleHelper $moduleHelper,
        ConfigProvider $configProvider
    ) {
        $this->moduleHelper = $moduleHelper;
        $this->configProvider = $configProvider;
    }

    public function getHeaders(): array
    {
        $apiToken = $this->configProvider->getApiKey();
        return [
            'Content-Type' => 'application/json',
            'Api-Token' => $apiToken,
            'x-mondu-trace-id' => $this->getUUIDV4(),
            'x-mondu-parent-span-id' => $this->getUUIDV4(),
            'x-plugin-version' => $this->getPluginVersion(),
            'x-plugin-name' => $this->getPluginName()
        ];
    }

    private function getUUIDV4(): string
    {
//        $data = PHP_MAJOR_VERSION < 7 ? openssl_random_pseudo_bytes(16) : random_bytes(16);
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // Set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function getPluginVersion(): string
    {
        return $this->moduleHelper->getModuleVersion();
    }

    private function getPluginName(): string
    {
        return $this->moduleHelper->getModuleNameForApi();
    }
}
