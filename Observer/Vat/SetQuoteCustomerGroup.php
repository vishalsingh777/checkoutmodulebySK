<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Insead\CustomCheckout\Model\Vat\CustomerGroupResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Temporarily swaps the quote's customer group to one of the 6 existing
 * VAT/tax groups (see CustomerGroupResolver) just before tax totals are
 * collected, so Tax Rules keyed to those groups apply correctly. The
 * matching RestoreQuoteCustomerGroup observer puts the original group back
 * on sales_quote_address_collect_totals_after.
 *
 * Guarded on `insead_billing_confirmed` so this ONLY ever touches quotes
 * that went through this module's own checkout flow (see
 * Controller\Billing\Save) — every other quote on the site (any other
 * store/flow, including ones Ewave_CustomerVat still handles) is untouched.
 *
 * Also skips any store with `insead_checkout/gst_group/enabled` on — those
 * stores use Observer\Gst\SetQuoteCustomerGroup's B2C/B2C_GST/B2B/B2B_EXEMPT
 * mapping instead, and having both mechanisms swap the same quote's group
 * within one totals-collection cycle would corrupt whichever one restores
 * last (see that class's docblock). Deliberately NOT gated on
 * `programme_in_singapore` — that flag means "the course takes place in
 * Singapore" and is already true on this mechanism's own stores
 * (icp_fra/lhub_fra/icp_usa), so using it here would have disabled this
 * working mechanism on those stores.
 */
class SetQuoteCustomerGroup implements ObserverInterface
{
    public const PROCESSED_FLAG = 'insead_vat_group_processed';

    public function __construct(
        private readonly CustomerGroupResolver $groupResolver,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote || !$quote->getData('insead_billing_confirmed')) {
            return;
        }
        if ($quote->hasData(self::PROCESSED_FLAG)) {
            return; // already swapped earlier in this same totals-collection cycle
        }
        if ($this->scopeConfig->isSetFlag(
            'insead_checkout/gst_group/enabled',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        )) {
            return;
        }

        $address = $quote->getBillingAddress();
        if (!$address || !$address->getCountryId()) {
            return;
        }

        $hasLetter = (bool) ($quote->getData('certificate_id') ?: $quote->getData('tax_exempt_file'));
        // Read from the quote's own vat_intracommunity column, not
        // $address->getVatId() — the latter does not reliably survive to
        // the final placed order in this checkout flow (confirmed across
        // multiple real orders: correct throughout Controller\Billing\Save,
        // even after an explicit raw SQL UPDATE, yet NULL on the order
        // regardless — something later in native checkout's own payment
        // step overwrites the address). vat_intracommunity has been
        // reliable on every test since it's never touched after Save.php sets it.
        $vatNumber = (string) $quote->getData('vat_intracommunity');
        $groupId = $this->groupResolver->resolve($address, $vatNumber, $hasLetter);

        if ($groupId !== null && $groupId !== (int) $quote->getCustomerGroupId()) {
            $address->setData('insead_prev_customer_group_id', $quote->getCustomerGroupId());
            $quote->setCustomerGroupId($groupId);
        }

        $quote->setData(self::PROCESSED_FLAG, true);
    }
}
