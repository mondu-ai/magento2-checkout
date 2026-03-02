<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class ModuleHelper
{
    public const MODULE_NAME = 'Mondu_Mondu';

    /**
     * @param ModuleListInterface $moduleList
     * @param ProductMetadataInterface $productMetadata
     * @param ResourceConnection $resourceConnection
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList $directoryList,
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
     * Returns the Mondu module version with multiple fallbacks.
     *
     * Tries: ModuleList -> Database (setup_module) -> module.xml -> composer.json -> default.
     *
     * @return string
     */
    public function getModuleVersion(): string
    {
        try {
            $moduleData = $this->moduleList->getOne(self::MODULE_NAME);
            if ($moduleData && isset($moduleData['setup_version']) && !empty($moduleData['setup_version'])) {
                return (string) $moduleData['setup_version'];
            }
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Continue to next fallback
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('setup_module');
            $select = $connection->select()
                ->from($tableName, ['schema_version'])
                ->where('module = ?', self::MODULE_NAME)
                ->limit(1);
            $version = $connection->fetchOne($select);
            if ($version && !empty($version)) {
                return (string) $version;
            }
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Continue to next fallback
        }

        try {
            $possiblePaths = [
                $this->directoryList->getPath(DirectoryList::APP) . '/code/Mondu/Mondu/etc/module.xml',
                $this->directoryList->getPath(DirectoryList::ROOT)
                    . '/vendor/mondu_gmbh/magento2-payment/etc/module.xml',
                dirname(__DIR__, 2) . '/etc/module.xml', // phpcs:ignore Magento2.Functions.DiscouragedFunction
            ];

            // phpcs:disable Magento2.Functions.DiscouragedFunction
            foreach ($possiblePaths as $moduleXmlPath) {
                if (file_exists($moduleXmlPath) && is_readable($moduleXmlPath)) {
                    $content = file_get_contents($moduleXmlPath);
                    // phpcs:enable Magento2.Functions.DiscouragedFunction
                    if ($content && preg_match('/setup_version=["\']([^"\']+)["\']/', $content, $matches)) {
                        return $matches[1];
                    }
                }
            }
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Continue to next fallback
        }

        try {
            $possiblePaths = [
                $this->directoryList->getPath(DirectoryList::ROOT)
                    . '/vendor/mondu_gmbh/magento2-payment/composer.json',
                $this->directoryList->getPath(DirectoryList::APP) . '/code/Mondu/Mondu/composer.json',
                dirname(__DIR__, 2) . '/composer.json', // phpcs:ignore Magento2.Functions.DiscouragedFunction
            ];

            // phpcs:disable Magento2.Functions.DiscouragedFunction
            foreach ($possiblePaths as $composerJsonPath) {
                if (file_exists($composerJsonPath) && is_readable($composerJsonPath)) {
                    $content = file_get_contents($composerJsonPath);
                    // phpcs:enable Magento2.Functions.DiscouragedFunction
                    if ($content) {
                        $data = json_decode($content, true);
                        if (isset($data['version']) && !empty($data['version'])) {
                            return (string) $data['version'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Continue to default
        }

        return '';
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
