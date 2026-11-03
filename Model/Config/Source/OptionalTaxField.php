<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Source model for the "Show Optional Tax & Legal Fields" admin multiselect
 * (insead_checkout/insead_customcheckout/show_optional_tax_fields). Every
 * option here corresponds to a field marked type:'opt' in billing-form.js's
 * Tax & Legal Information matrix (fieldDefs) — mandatory fields (marked
 * type:'req') and PO Number are never affected by this setting; they always
 * show. Unchecked here = hidden on the Billing Information step, across
 * every country/tax-status combination that field would otherwise appear
 * in. See Block\Checkout::isOptionalTaxFieldEnabled().
 */
class OptionalTaxField implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'duns', 'label' => __('D-U-N-S Number')],
            ['value' => 'routing', 'label' => __('Routing Address')],
            ['value' => 'taxreg', 'label' => __('Tax Registration Number')],
            ['value' => 'cert_opt', 'label' => __('Certificate ID (non-exempt cases)')],
            ['value' => 'reg_type', 'label' => __('Registration Type & Value')],
        ];
    }
}