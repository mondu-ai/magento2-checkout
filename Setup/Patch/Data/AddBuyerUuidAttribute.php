<?php

declare(strict_types=1);

namespace Mondu\Mondu\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddBuyerUuidAttribute implements DataPatchInterface
{
    /**
     * @param CustomerSetupFactory $customerSetupFactory
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly CustomerSetupFactory $customerSetupFactory,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    /**
     * Adds mondu_buyer_uuid attribute to the customer entity.
     *
     * @return void
     */
    public function apply(): void
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, 'mondu_buyer_uuid', [
            'type'         => 'varchar',
            'label'        => 'Mondu Buyer UUID',
            'input'        => 'text',
            'required'     => false,
            'visible'      => true,
            'system'       => false,
            'user_defined' => false,
            'position'     => 200,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'mondu_buyer_uuid');
        $attribute->setData('used_in_forms', ['adminhtml_customer']);
        $attribute->save();
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
