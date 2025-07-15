<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class HeadersHelper
{
    /**
     * @param ConfigProvider $configProvider
     * @param ModuleHelper $moduleHelper
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly ModuleHelper $moduleHelper,
    ) {
    }

    /**
     * Returns the set of headers required for Mondu API requests.
     *
     * @throws Exception
     * @return array
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Api-Token' => $this->configProvider->getApiKey(),
            'x-mondu-trace-id' => $this->getUUIDV4(),
            'x-mondu-parent-span-id' => $this->getUUIDV4(),
            'x-plugin-version' => $this->getPluginVersion(),
            'x-plugin-name' => $this->getPluginName(),
        ];
    }

    /**
     * Generates a UUID v4 string according to RFC 4122.
     *
     * @throws Exception
     * @return string
     */
    private function getUUIDV4(): string
    {
        $data = random_bytes(16);
        //phpcs:ignore
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        //phpcs:ignore
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Returns the version of the Mondu plugin.
     *
     * @return string
     */
    private function getPluginVersion(): string
    {
        return $this->moduleHelper->getModuleVersion();
    }

    /**
     * Returns the Mondu plugin name for API headers.
     *
     * @return string
     */
    private function getPluginName(): string
    {
        return $this->moduleHelper->getModuleNameForApi();
    }
}
