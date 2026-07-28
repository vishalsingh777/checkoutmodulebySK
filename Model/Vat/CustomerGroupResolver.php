<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Vat;

use Magento\Customer\Model\Vat;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves which of the 6 existing VAT/tax customer groups a quote's billing
 * address falls into — EU vs non-EU, VAT-Intracommunity validity (via core
 * Magento\Customer\Model\Vat / VIES, same as Controller\Vat\Validate), and
 * whether a supporting document ("Letter") is present.
 *
 * These are the same 6 groups (IDs 14/17/20/23/26/29) Ewave_CustomerVat has
 * used up to now — this class only relocates who computes the assignment,
 * it does not introduce new groups/tax classes/tax rules. See
 * Observer\Vat\SetQuoteCustomerGroup for where this is applied.
 */
class CustomerGroupResolver
{
    private const CONFIG_PATH_PREFIX = 'insead_checkout/vat_group/';

    public function __construct(
        private readonly Vat $vat,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param Address $address Quote billing address — read for country, and
     *                         used to cache the VIES result (see
     *                         resolveEuPath) so it isn't re-checked on every
     *                         totals collection within this request.
     * @param string $vatNumber VAT Intracommunity Number — passed explicitly
     *                          by the caller (read from the quote's own
     *                          `vat_intracommunity` custom column, NOT
     *                          $address->getVatId()). Core Magento's
     *                          quote_address.vat_id does not reliably survive
     *                          to the final placed order in this checkout
     *                          flow — confirmed directly across multiple real
     *                          orders (correct in memory throughout
     *                          Controller\Billing\Save, even after an
     *                          explicit raw SQL UPDATE, yet NULL on the
     *                          order regardless — something later in native
     *                          checkout's own payment/order-placement step
     *                          overwrites the address). `vat_intracommunity`
     *                          has been reliable on every test, so that's
     *                          the source of truth here instead.
     * @param bool $hasLetter Whether a supporting document (certificate_id /
     *                        tax_exempt_file, or company for some sites) is present.
     * @return int|null Customer group ID, or null if not configured for this scope.
     */
    public function resolve(Address $address, string $vatNumber, bool $hasLetter): ?int
    {
        $countryId = (string) $address->getCountryId();
        $store = $address->getQuote() ? $address->getQuote()->getStoreId() : null;

        $path = $this->vat->isCountryInEU($countryId)
            ? $this->resolveEuPath($address, $countryId, $vatNumber, $hasLetter)
            : ($hasLetter ? 'non_eu_with_letter' : 'non_eu_without_letter');

        $groupId = $this->scopeConfig->getValue(
            self::CONFIG_PATH_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $store
        );

        return $groupId !== null ? (int) $groupId : null;
    }

    /**
     * A VAT number that fails VIES validation AND one VIES couldn't check at
     * all (service down) are both treated as "without VAT" — the safer
     * default for tax compliance is to charge tax when validity can't be
     * confirmed, not to zero-rate on an unconfirmed number.
     *
     * Reuses the cached result on the address (same columns core Magento's
     * own Quote\Observer\Frontend\Quote\Address\VatValidator and
     * Ewave_CustomerVat's VatValidator already use) rather than calling VIES
     * again whenever the VAT number/country haven't changed within THIS
     * request/quote object. Without this, a live SOAP call fires on every
     * totals collection — every page load, refresh, and shipping-step
     * recalculation, not just the one Save click — and VIES starts
     * rejecting the burst with MS_MAX_CONCURRENT_REQ.
     *
     * Caches the VIES result on the address object itself (setData only, no
     * save()) purely to avoid repeat VIES calls within THIS request/quote
     * object if this method runs more than once during one totals
     * collection cycle — not intended to survive across separate requests.
     */
    private function resolveEuPath(Address $address, string $countryId, string $vatNumber, bool $hasLetter): string
    {
        $isValid = false;

        if ($vatNumber !== '') {
            if ($vatNumber === (string) $address->getData('validated_vat_number')
                && $countryId === (string) $address->getData('validated_country_code')
            ) {
                $isValid = (bool) $address->getData('vat_is_valid')
                    && (bool) $address->getData('vat_request_success');
            } else {
                $result = $this->vat->checkVatNumber($countryId, $vatNumber);
                $isValid = $result->getRequestSuccess() && $result->getIsValid();

                $address->setData('validated_vat_number', $vatNumber);
                $address->setData('validated_country_code', $countryId);
                $address->setData('vat_is_valid', (int) $result->getIsValid());
                $address->setData('vat_request_success', (int) $result->getRequestSuccess());
                $address->setData('vat_request_id', (string) $result->getRequestIdentifier());
                $address->setData('vat_request_date', (string) $result->getRequestDate());
            }
        }

        if ($isValid) {
            return $hasLetter ? 'eu_with_vat_and_letter' : 'eu_with_vat_without_letter';
        }
        return $hasLetter ? 'eu_without_vat_and_letter' : 'eu_without_vat_without_letter';
    }
}
