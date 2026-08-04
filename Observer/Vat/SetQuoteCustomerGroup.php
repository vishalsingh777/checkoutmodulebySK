<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Insead\CustomCheckout\Model\Vat\CustomerGroupResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Sets the quote's customer group to one of 4 VAT/tax groups (B2C /
 * B2B_REGISTERED / B2B_NON_REGISTERED / B2B_TAX_EXEMPT — see
 * CustomerGroupResolver) whenever totals are collected, so Tax Rules keyed
 * to those groups apply correctly. Mirrors Observer\Gst\SetQuoteCustomerGroup
 * exactly — same shape, since both resolvers now use the same
 * financing_profile + tax_registration_status decision logic. Temporary
 * (see RestoreQuoteCustomerGroup) rather than persisted, unlike GST — see
 * that class's docblock for why GST was changed to persist and VAT wasn't
 * asked to.
 *
 * Guarded on `insead_billing_confirmed` so this ONLY ever touches quotes
 * that went through this module's own checkout flow (see
 * Controller\Billing\Save) — every other quote on the site (any other
 * store/flow, including ones Ewave_CustomerVat still handles) is untouched.
 *
 * Also skips any store with `insead_checkout/gst_group/enabled` on — those
 * stores use Observer\Gst\SetQuoteCustomerGroup's 6-group mapping instead
 * (see Model\Gst\CustomerGroupResolver), and having both mechanisms swap the
 * same quote's group within one totals-collection cycle would corrupt
 * whichever one restores last (see that class's docblock).
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

        $groupId = $this->groupResolver->resolve($quote);

        if ($groupId !== null && $groupId !== (int) $quote->getCustomerGroupId()) {
            $address = $quote->getBillingAddress();
            if ($address) {
                $address->setData('insead_prev_customer_group_id', $quote->getCustomerGroupId());
            }
            $quote->setCustomerGroupId($groupId);
        }

        $quote->setData(self::PROCESSED_FLAG, true);
    }
}
