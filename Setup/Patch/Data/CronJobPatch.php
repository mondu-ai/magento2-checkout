<?php

declare(strict_types=1);

namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CronJobPatch implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * Copies config value from require_invoice to cron_require_invoice for compatibility.
     *
     * @return void
     */
    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $tableName = $this->moduleDataSetup->getTable('core_config_data');
        $previousValue = $connection->fetchOne(
            $connection->select()
                ->from($tableName, ['value'])
                ->where('path = ?', 'payment/mondu/require_invoice')
        );

        if ($previousValue) {
            $connection->delete($tableName, ['path = "payment/mondu/cron_require_invoice"']);
            $connection->insert($tableName, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => 'payment/mondu/cron_require_invoice',
                'value' => $previousValue,
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
