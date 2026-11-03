<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Gst;

use Insead\CustomCheckout\Model\Gst\CustomerGroupResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Temporarily swaps the quote's customer group to one of the 6 Singapore GST
 * groups (B2C / B2C_SG_GST_LIABLE / B2B_REGISTERED / B2B_NON_REGISTERED /
 * B2B_TAX_EXEMPT / B2B_SG_GST_LIABLE — see CustomerGroupResolver) just
 * before tax totals are collected, so Tax Rules keyed to those groups apply
 * correctly. The matching RestoreQuoteCustomerGroup observer puts the
 * original group back on sales_quote_address_collect_totals_after — same
 * quote-scoped, per-request swap/restore shape as Observer\Vat's mechanism,
 * never persisted to the customer's account.
 *
 * Guarded on `insead_checkout/gst_group/enabled` — the SINGLE flag for
 * Singapore GST, gating both this backend group assignment and the frontend
 * GST Declaration / residency declaration fields (see Block\Checkout).
 * Should be set to Yes only at the scope of the
 * Singapore selling-entity store(s) (e.g. lhub_sgp) — see
 * etc/adminhtml/system.xml. Observer\Vat\SetQuoteCustomerGroup checks this
 * same flag and skips when it's on, so the two mechanisms are mutually
 * exclusive per store and never both try to swap the same quote's group
 * within one totals-collection cycle.
 *
 * Also guarded on `insead_billing_confirmed`, same as the VAT observer, so
 * this only ever touches quotes that went through this module's own
 * checkout (see Controller\Billing\Save).
 */
class SetQuoteCustomerGroup implements ObserverInterface
{
    public const PROCESSED_FLAG = 'insead_gst_group_processed';

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
        if (!$this->scopeConfig->isSetFlag(
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
                $address->setData('insead_prev_gst_customer_group_id', $quote->getCustomerGroupId());
            }
            $quote->setCustomerGroupId($groupId);
        }

        $quote->setData(self::PROCESSED_FLAG, true);
    }
}
