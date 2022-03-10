<?php
namespace Mondu\Mondu\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;

        $installer->startSetup();

        $orderTable = 'sales_order';

        $installer->getConnection()
            ->addColumn(
                $installer->getTable($orderTable),
                'mondu_reference_id',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'nullable' => false,
                    'comment' => 'mondu_reference_id'
                ]
            );

        $installer->endSetup();
    }
}
