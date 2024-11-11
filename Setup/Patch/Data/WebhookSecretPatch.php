<?php
namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class WebhookSecretPatch implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

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
        $liveWebhookSecret = $this->moduleDataSetup->getConnection()->fetchOne('SELECT `value` FROM ' . $tableName . ' WHERE `path` = "payment/mondu/live_webhook_secret"');
        $sandboxWebhookSecret = $this->moduleDataSetup->getConnection()->fetchOne('SELECT `value` FROM ' . $tableName . ' WHERE `path` = "payment/mondu/sandbox_webhook_secret"');

        $this->moduleDataSetup->getConnection()->delete($tableName, ['path = "payment/mondu/sandbox_webhook_secret"']);
        $this->moduleDataSetup->getConnection()->delete($tableName, ['path = "payment/mondu/live_webhook_secret"']);

        $this->moduleDataSetup->getConnection()->insert($tableName, [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => 'payment/mondu/sandbox_webhook_secret',
            'value' => $sandboxWebhookSecret,
        ]);

        $this->moduleDataSetup->getConnection()->insert($tableName, [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => 'payment/mondu/live_webhook_secret',
            'value' => $liveWebhookSecret,
        ]);

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
