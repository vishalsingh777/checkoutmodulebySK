<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Total\Quote;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Store\Model\ScopeInterface;

/**
 * Adds Singapore GST for the one case the Customer Group Mapping spec can't
 * express through a group swap: a B2B customer on the Singapore selling
 * entity who is Tax Registered / Non Tax Registered (not Exempt — that
 * already moves to the zero-rated B2B_EXEMPT group via
 * Model\Gst\CustomerGroupResolver) but stays in the single "B2B" group
 * either way, with GST Yes/No decided by their `gst_declaration` answer, NOT
 * by billing/shipping country. Magento's tax engine can't produce two
 * different outcomes for the same (customer group, address, product)
 * combination, so this is a manual total, not a Tax Rule.
 *
 * `gst_declaration` has 4 existing options (billing-form.js's gstStatements),
 * not the simple Yes/No the spec table shows. Which of the 4 count as "GST
 * applies" is admin-configurable — see
 * `insead_checkout/gst_group/b2b_applicable_declarations`
 * (Model\Gst\Source\DeclarationOptions) — defaulting to statements 3 and 4,
 * a judgment call documented there, not derived from the spec table itself.
 *
 * Only affects the quote grand total and `insead_gst_amount` /
 * `insead_base_gst_amount` (queryable on quote/order for finance/reporting —
 * see Observer\Gst\CopyGstAmountToOrder). Deliberately NOT wired into the
 * checkout summary UI, PDF invoice, or order/credit memo totals blocks —
 * out of scope for this pass; the charged amount is correct, but it isn't
 * yet shown as its own line anywhere in the storefront/PDF/email.
 *
 * Caution: if a Tax Rule is ALSO added on the B2B tax class for a Singapore
 * zone (the country-based alternative considered and rejected in favor of
 * this), the two would stack for any order that both declares a Singapore
 * entity AND bills from Singapore. Use one mechanism or the other for this
 * scenario, not both.
 */
class Gst extends AbstractTotal
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        $this->setCode('insead_gst');
    }

    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        parent::collect($quote, $shippingAssignment, $total);

        $amount = 0.0;
        $baseAmount = 0.0;

        if ($this->appliesTo($quote)) {
            $rate = (float) $this->scopeConfig->getValue(
                'insead_checkout/gst_group/b2b_declaration_rate',
                ScopeInterface::SCOPE_STORE,
                $quote->getStoreId()
            ) / 100;

            if ($rate > 0) {
                $amount = round((float) $total->getSubtotal() * $rate, 2);
                $baseAmount = round((float) $total->getBaseSubtotal() * $rate, 2);
            }
        }

        if ($amount > 0) {
            $total->addTotalAmount($this->getCode(), $amount);
            $total->addBaseTotalAmount($this->getCode(), $baseAmount);
        }

        $quote->setData('insead_gst_amount', $amount);
        $quote->setData('insead_base_gst_amount', $baseAmount);

        return $this;
    }

    public function fetch(Quote $quote, Total $total): array
    {
        $amount = (float) $quote->getData('insead_gst_amount');
        if ($amount <= 0) {
            return [];
        }

        return [
            'code' => $this->getCode(),
            'title' => __('Singapore GST'),
            'value' => $amount,
        ];
    }

    private function appliesTo(Quote $quote): bool
    {
        if (!$this->scopeConfig->isSetFlag(
            'insead_checkout/gst_group/enabled',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        )) {
            return false;
        }

        if ((string) $quote->getData('financing_profile') !== 'b2b') {
            return false;
        }

        if ((string) $quote->getData('tax_registration_status') === 'exempt') {
            return false;
        }

        $configured = (string) $this->scopeConfig->getValue(
            'insead_checkout/gst_group/b2b_applicable_declarations',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        );
        $applicableValues = array_filter(explode(',', $configured), static fn ($v) => $v !== '');

        return in_array((string) $quote->getData('gst_declaration'), $applicableValues, true);
    }
}
