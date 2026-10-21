<?php

declare(strict_types=1);

namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class InvoicePdfTokenPatch implements DataPatchInterface
{
    public const CONFIG_PATH = 'payment/mondu/invoice_pdf_token_required_since';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * Records when invoice PDF links started to carry a token.
     *
     * Links sent to Mondu before this moment have no token and Mondu may still hand them out,
     * so the invoice PDF action keeps serving tokenless links for invoices created earlier.
     *
     * @return void
     */
    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('core_config_data'),
            [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => self::CONFIG_PATH,
                'value' => gmdate('Y-m-d H:i:s'),
            ],
            []
        );

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
