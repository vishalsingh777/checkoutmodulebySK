<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Vat;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves which of 4 VAT/tax customer groups a quote falls into: B2C,
 * B2B_REGISTERED, B2B_NON_REGISTERED, B2B_TAX_EXEMPT — the SAME shape as
 * Model\Gst\CustomerGroupResolver, by explicit decision: group assignment
 * for non-Singapore stores (icp_fra/lhub_fra/icp_usa etc.) uses only
 * `financing_profile` (B2C/B2B) and `tax_registration_status`
 * (reg/notreg/exempt) — the same two self-declared frontend fields GST
 * uses, nothing else.
 *
 * COMPLIANCE NOTE, on record: this replaces an earlier design that also
 * validated the EU VAT Intracommunity number against VIES (the EU's live
 * VAT-number verification service) and branched on EU-vs-non-EU country plus
 * whether a supporting "letter" (Certificate ID / Tax Exempt File) was
 * present — 6 groups in total. That EU-VAT-law-driven design existed because
 * applying 0%/reverse-charge VAT to a B2B sale under EU rules is generally
 * expected to rest on a validated VAT number, not self-declaration alone.
 * This resolver now applies reverse-charge/zero-rate treatment purely from
 * the customer's own "Tax Registered" selection, with NO VIES check gating
 * it. Controller\Vat\Validate (the live green-check on the billing form)
 * still calls VIES and still records the result — that verification is
 * simply no longer consulted here to decide the group/tax outcome.
 */
class CustomerGroupResolver
{
    private const CONFIG_PATH_PREFIX = 'insead_checkout/vat_group/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(Quote $quote): ?int
    {
        $financingProfile = (string) $quote->getData('financing_profile');

        $path = $financingProfile === 'b2b'
            ? $this->resolveB2bPath($quote)
            : 'b2c';

        $groupId = $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        );

        return $groupId !== null ? (int) $groupId : null;
    }

    private function resolveB2bPath(Quote $quote): string
    {
        $taxStatus = (string) $quote->getData('tax_registration_status');
        return match ($taxStatus) {
            'exempt' => 'b2b_tax_exempt',
            'notreg' => 'b2b_non_registered',
            default => 'b2b_registered',
        };
    }
}
