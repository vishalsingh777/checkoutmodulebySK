<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Source model for the consolidated "show for which profile(s)" admin
 * fields (show_gender_field, show_nationality_field, phone_required) —
 * one multiselect each, replacing what used to be a separate Yes/No field
 * per profile (show_gender_field_b2c + show_gender_field_b2b, etc.).
 * Renders as: "Show for: [ ] Self-funded (B2C)  [ ] Sponsored by
 * Organisation (B2B)". Saved value is a comma-separated string of
 * whichever of 'b2c'/'b2b' are checked (Magento's standard multiselect
 * storage format) — see Block\Checkout::isProfileFlagEnabled().
 */
class Profile implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'b2c', 'label' => __('Self-funded (B2C)')],
            ['value' => 'b2b', 'label' => __('Sponsored by Organisation (B2B)')],
        ];
    }
}
