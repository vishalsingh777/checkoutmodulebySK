<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Gst;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves which of the 4 Singapore GST customer groups (B2C / B2C_GST / B2B
 * / B2B_EXEMPT) a quote falls into, per the Customer Group Mapping spec for
 * programmes sold by the Singapore legal entity. Mirrors
 * Model\Vat\CustomerGroupResolver's shape (pure decision logic, config-driven
 * group IDs) — see Observer\Gst\SetQuoteCustomerGroup for where this applies.
 *
 * Only B2C's Singapore-residency declaration and B2B's Tax Exempt status
 * change the group. A B2B customer who declares having (or not having) a
 * Singapore entity (`gst_declaration`) stays in the B2B group either way per
 * spec — that declaration is kept as a compliance record only; GST for that
 * case is expected to come from a Tax Rule matching the B2B group's Tax
 * Class against the billing/shipping country, not from this resolver (a
 * single customer group cannot produce two different tax outcomes on its
 * own — see Observer\Gst\SetQuoteCustomerGroup's docblock).
 */
class CustomerGroupResolver
{
    private const CONFIG_PATH_PREFIX = 'insead_checkout/gst_group/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(Quote $quote): ?int
    {
        $financingProfile = (string) $quote->getData('financing_profile');

        $path = $financingProfile === 'b2b'
            ? $this->resolveB2bPath($quote)
            : $this->resolveB2cPath($quote);

        $groupId = $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        );

        return $groupId !== null ? (int) $groupId : null;
    }

    /**
     * 'disagree' = shopper does NOT certify they'll be outside Singapore
     * during the course — i.e. they WILL be in Singapore, so GST applies.
     * Blank (declaration not shown/not answered, e.g. SG/FR/AE/US billing
     * country per billing-form.js's residencyExcluded) keeps the default B2C
     * group, matching the spec's "Standard B2C customer" row.
     */
    private function resolveB2cPath(Quote $quote): string
    {
        $declaration = (string) $quote->getData('residency_declaration');
        return $declaration === 'disagree' ? 'b2c_gst' : 'b2c';
    }

    /**
     * 'reg' and 'notreg' both stay on the default B2B group — only 'exempt'
     * moves to the dedicated no-GST group.
     */
    private function resolveB2bPath(Quote $quote): string
    {
        $taxStatus = (string) $quote->getData('tax_registration_status');
        return $taxStatus === 'exempt' ? 'b2b_exempt' : 'b2b';
    }
}
