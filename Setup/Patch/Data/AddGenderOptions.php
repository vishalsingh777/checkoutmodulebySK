<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds "Non Binary" and "Prefer not to answer" option values to the
 * customer gender EAV attribute so the checkout gender dropdown can
 * save all four options to the customer account.
 */
class AddGenderOptions implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): static
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $eavSetup   = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attribute  = $eavSetup->getAttribute(Customer::ENTITY, 'gender');

        if (empty($attribute)) {
            $this->moduleDataSetup->getConnection()->endSetup();
            return $this;
        }

        $attributeId = (int) $attribute['attribute_id'];
        $conn        = $this->moduleDataSetup->getConnection();
        $optionTable = $this->moduleDataSetup->getTable('eav_attribute_option');
        $valueTable  = $this->moduleDataSetup->getTable('eav_attribute_option_value');

        // Fetch existing option labels so we don't create duplicates.
        $existing = $conn->fetchCol(
            $conn->select()
                ->from(['o' => $optionTable], [])
                ->join(['v' => $valueTable], 'o.option_id = v.option_id', ['v.value'])
                ->where('o.attribute_id = ?', $attributeId)
                ->where('v.store_id = 0')
        );
        $existingLower = array_map('strtolower', $existing);

        $toAdd = [
            'Non Binary',
            'Prefer not to answer',
        ];

        foreach ($toAdd as $label) {
            if (in_array(strtolower($label), $existingLower, true)) {
                continue; // already present — skip
            }
            $conn->insert($optionTable, [
                'attribute_id' => $attributeId,
                'sort_order'   => 0,
            ]);
            $optionId = (int) $conn->lastInsertId($optionTable);
            $conn->insert($valueTable, [
                'option_id' => $optionId,
                'store_id'  => 0,   // admin / default label
                'value'     => $label,
            ]);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddPeoplesoftIdCustomerAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
