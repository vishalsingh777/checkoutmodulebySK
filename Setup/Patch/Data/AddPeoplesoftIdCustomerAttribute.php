<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers peoplesoft_id as a customer EAV attribute so it appears
 * in the admin customer account information section.
 *
 * The column itself is added to customer_entity by db_schema.xml;
 * this patch makes Magento aware of it as a proper customer attribute.
 */
class AddPeoplesoftIdCustomerAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public function apply(): static
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, 'peoplesoft_id', [
            'type'         => 'varchar',
            'label'        => 'PeopleSoft ID',
            'input'        => 'text',
            'required'     => false,
            'visible'      => true,
            'user_defined' => true,
            'position'     => 999,
            'system'       => false,
            'is_used_in_grid'       => false,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        // Make it visible in the admin customer edit form.
        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'peoplesoft_id');
        $attribute->setData('used_in_forms', ['adminhtml_customer']);
        $attribute->save();

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
