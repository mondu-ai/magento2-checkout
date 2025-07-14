<?php

declare(strict_types=1);

namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class WebhookSecretPatch implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * Deletes and re-inserts Mondu webhook secrets for sandbox and live modes.
     *
     * @return void
     */
    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $tableName = $this->moduleDataSetup->getTable('core_config_data');
        foreach (['sandbox', 'live'] as $env) {
            $path = "payment/mondu/{$env}_webhook_secret";

            $value = $connection->fetchOne(
                $connection->select()
                    ->from($tableName, ['value'])
                    ->where('path = ?', $path)
            );
            $connection->delete($tableName, ['path = ?' => $path]);
            $connection->insert($tableName, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => $path,
                'value' => $value,
            ]);
        }

        $connection->endSetup();
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Get aliases (previous names) for the patch.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
