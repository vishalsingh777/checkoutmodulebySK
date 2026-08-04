<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Gst;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves which of the 6 Singapore GST customer groups a quote falls into:
 * B2C, B2C_SG_GST_LIABLE, B2B_REGISTERED, B2B_NON_REGISTERED,
 * B2B_TAX_EXEMPT, B2B_SG_GST_LIABLE. Replaces the earlier 4-group design
 * (B2C/B2C_GST/B2B/B2B_EXEMPT) — see Observer\Gst\SetQuoteCustomerGroup for
 * where this applies.
 *
 * Each group maps 1:1 to its own Magento Tax Class, so native Tax Rules do
 * the actual GST calculation — there is deliberately NO separate manual GST
 * total anymore (the old Model\Total\Quote\Gst flat-rate total for B2B is
 * retired; it would double-charge GST now that a Tax Rule handles it).
 *
 * B2B used to stay in a single group regardless of the Singapore GST
 * declaration. Now it starts as one of THREE base groups driven by Tax
 * Status alone (Registered / Non-Registered / Tax Exempt, each its own Tax
 * Class for reverse-charge/zero-rate purposes) — but ALL THREE collapse into
 * the SAME B2B_SG_GST_LIABLE group the moment the GST Declaration is
 * answered with statement 4 ("None of the above apply"), since at that
 * point they all owe the identical 9% GST regardless of which of the three
 * they started from. Statements 1-3 (or the declaration not being shown at
 * all) leave Tax Status as the only thing that matters.
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
     * Blank (declaration not shown/not answered — billing = SG, GST config
     * off, or not a Singapore-entity store) keeps the default B2C group.
     */
    private function resolveB2cPath(Quote $quote): string
    {
        $declaration = (string) $quote->getData('residency_declaration');
        return $declaration === 'disagree' ? 'b2c_sg_gst_liable' : 'b2c';
    }

    /**
     * Statement 4 ("None of the above apply") always wins, regardless of Tax
     * Status — a Tax-Exempt B2B customer who selects statement 4 still owes
     * GST, per the table's explicit note that this group "no longer skips
     * the SG question." Which statement(s) count as liable is admin
     * configurable (`b2b_applicable_declarations`, defaulting to statement 4
     * only) rather than hardcoded, matching how DeclarationOptions already
     * exposes all 4 statements to admin.
     */
    private function resolveB2bPath(Quote $quote): string
    {
        $declaration = (string) $quote->getData('gst_declaration');
        if ($declaration !== '' && $this->isGstLiableDeclaration($declaration, $quote)) {
            return 'b2b_sg_gst_liable';
        }

        $taxStatus = (string) $quote->getData('tax_registration_status');
        return match ($taxStatus) {
            'exempt' => 'b2b_tax_exempt',
            'notreg' => 'b2b_non_registered',
            default => 'b2b_registered',
        };
    }

    private function isGstLiableDeclaration(string $declaration, Quote $quote): bool
    {
        $configured = (string) $this->scopeConfig->getValue(
            'insead_checkout/gst_group/b2b_applicable_declarations',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        );
        $liableValues = array_filter(array_map('trim', explode(',', $configured)));

        return in_array($declaration, $liableValues, true);
    }
}
