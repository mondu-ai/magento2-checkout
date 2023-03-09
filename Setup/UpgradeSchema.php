<?php
namespace Mondu\Mondu\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;

        $installer->startSetup();

        // mondu_transactions table migration
        $tableName = $installer->getTable('mondu_transactions');
        if ($installer->getConnection()->isTableExists($tableName) != true) {
            $table = $installer->getConnection()
                ->newTable($tableName)
                ->addColumn('entity_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, array(
                    'identity'  => true,
                    'unsigned'  => true,
                    'nullable'  => false,
                    'primary'   => true,
                ), 'Log Id')
                ->addColumn('store_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, array(
                    'unsigned'  => true,
                ), 'Store Id')
                ->addColumn('order_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, array(
                    'unsigned'  => true,
                ), 'Order Id')
                ->addColumn('reference_id', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,64,array(
                    'nullable'  => false,
                ),'Mondu reference id')
                ->addColumn('created_at', \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP, null,
                    array('default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT_UPDATE), 'created at')
                ->addColumn('customer_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, array(
                    'unsigned'  => true,
                ), 'Customer Id')
                ->addColumn('mode', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,12,array(
                    'nullable'  => true,
                ), 'Transaction mode')
                ->addColumn('mondu_state', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,128,array(
                    'nullable'  => true,
                ), 'Mondu state')
                ->addColumn('addons', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,4096,array(
                    'nullable'  => false,
                ), 'Order addons')
                ->addColumn('invoice_iban', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT, 255, array(
                    'nullable' => true
                ))
                ->addIndex($installer->getIdxName('mondu_transactions', array('customer_id')),
                    array('customer_id'))
                ->addForeignKey($installer->getFkName('mondu_transactions', 'customer_id', 'customer/entity', 'entity_id'),
                    'customer_id', $installer->getTable('customer_entity'), 'entity_id',
                    \Magento\Framework\DB\Ddl\Table::ACTION_SET_NULL, \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE);

            $installer->getConnection()->createTable($table);
        }

        if ($installer->getConnection()->tableColumnExists($tableName, 'skip_ship_observer') === false) {
            $installer->getConnection()->addColumn($tableName, 'skip_ship_observer', array(
                'type'      => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
                'nullable'  => true,
                'comment'   => 'Skip ship observer'
            ));
        }

        if ($installer->getConnection()->tableColumnExists($tableName, 'payment_method') === false) {
            $installer->getConnection()->addColumn($tableName, 'payment_method', array(
                'type'      => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                'nullable'  => true,
                'comment'   => 'Mondu payment method'
            ));
        }

        if ($installer->getConnection()->tableColumnExists($tableName, 'authorized_net_term') === false) {
            $installer->getConnection()->addColumn($tableName, 'authorized_net_term', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, array(
                    'unsigned'  => true,
                    'nullable'  => true,
            ), 'Mondu authorized net term');
        }

        $this->createMonduTransactionItemsTable($installer);
        $installer->endSetup();
    }


    public function createMonduTransactionItemsTable(SchemaSetupInterface $installer) {
        $tableName = $installer->getTable('mondu_transaction_items');
        if ($installer->getConnection()->isTableExists($tableName) != true) {
            $table = $installer->getConnection()
            ->newTable($tableName)
            ->addColumn('entity_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                'identity'  => true,
                'unsigned'  => true,
                'nullable'  => false,
                'primary'   => true
            ], 'Transaction Item id')
            ->addColumn('mondu_transaction_id',\Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => false
            ])
            ->addColumn('product_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => false
            ])
            ->addColumn('order_item_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => true
            ])
            ->addColumn('quote_item_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => true
            ]);

            $installer->getConnection()->createTable($table);
        }
    }
}
