<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Gst\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * The 4 GST Declaration statement options a B2B customer can select on the
 * Billing Information step (see billing-form.js's `gstStatements` — values
 * and text must stay in sync with that file). Backs the "Which Statements
 * Move the Customer to Singapore GST Liable" admin field, so the mapping
 * Model\Gst\CustomerGroupResolver uses is admin-configurable instead of a
 * hardcoded array.
 */
class DeclarationOptions implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('1. We do NOT have any branch/office/personnel in Singapore')],
            ['value' => '2', 'label' => __('2. We have a Singapore presence, BUT the service is NOT used by it')],
            ['value' => '3', 'label' => __('3. Participant(s) employed by a GST-registered Singapore entity')],
            ['value' => '4', 'label' => __('4. None of the above apply (GST applies)')],
        ];
    }
}
