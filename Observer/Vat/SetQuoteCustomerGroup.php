<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Insead\CustomCheckout\Model\Vat\CustomerGroupResolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

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
 */
class SetQuoteCustomerGroup implements ObserverInterface
{
    public const PROCESSED_FLAG = 'insead_vat_group_processed';

    public function __construct(
        private readonly CustomerGroupResolver $groupResolver
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
