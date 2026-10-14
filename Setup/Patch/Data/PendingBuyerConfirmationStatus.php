<?php

declare(strict_types=1);

namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

class PendingBuyerConfirmationStatus implements DataPatchInterface
{
    public const STATUS_CODE = 'mondu_pending_buyer_confirmation';
    public const STATUS_LABEL = 'Pending Buyer Confirmation';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * Creates the "Pending Buyer Confirmation" order status and assigns it to payment_review state.
     *
     * @return void
     */
    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $statusTable = $this->moduleDataSetup->getTable('sales_order_status');
        $stateTable = $this->moduleDataSetup->getTable('sales_order_status_state');

        $connection->insertOnDuplicate($statusTable, [
            'status' => self::STATUS_CODE,
            'label'  => self::STATUS_LABEL,
        ]);

        $connection->insertOnDuplicate($stateTable, [
            'status'           => self::STATUS_CODE,
            'state'            => Order::STATE_PAYMENT_REVIEW,
            'is_default'       => 0,
            'visible_on_front' => 1,
        ]);

        $connection->endSetup();
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
