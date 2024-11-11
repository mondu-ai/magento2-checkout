<?php
namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CronJobPatch implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $tableName = $this->moduleDataSetup->getTable('core_config_data');
        $previousValue = $this->moduleDataSetup->getConnection()->fetchOne('SELECT `value` FROM ' . $tableName . ' WHERE `path` = "payment/mondu/require_invoice"');

        if ($previousValue) {
            $this->moduleDataSetup
                ->getConnection()
                ->delete($tableName, ['path = "payment/mondu/cron_require_invoice"']);

            $this->moduleDataSetup->getConnection()->insert($tableName, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => 'payment/mondu/cron_require_invoice',
                'value' => $previousValue,
            ]);
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
