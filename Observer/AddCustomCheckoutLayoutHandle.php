<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Gates the INSEAD custom checkout takeover behind
 * Stores > Configuration > INSEAD > Checkout > INSEAD Custom Checkout >
 * Enable Custom Checkout Step for the current store view, AND whether THIS
 * quote's Billing Information step has already been confirmed.
 *
 * Hybrid flow: the custom takeover only covers Step 1 (Tax & Legal / Billing
 * Information — no native equivalent exists for that). Once
 * Controller\Billing\Save marks the quote as `insead_billing_confirmed`, this
 * observer stops adding the takeover handle, so the NEXT /checkout page load
 * (Billing\Save redirects the browser there) falls through to
 * checkout_index_index.xml completely untouched — genuine, unmodified native
 * Magento checkout. Payment therefore runs through native's own renderer-list
 * for every enabled payment method (Stripe, Braintree, PayPal, Sogecommerce,
 * Check/Money Order, COD, anything enabled in the future) with zero custom
 * code: no method-specific work here, no risk of falling out of sync with
 * what native does, and no per-gateway maintenance burden on this module.
 *
 * A quote reverts to needing the custom step again if it's abandoned and a
 * new one is created (the flag lives on the quote, not the customer/session),
 * which is the correct behavior — Tax & Legal details are quote-specific.
 */
class AddCustomCheckoutLayoutHandle implements ObserverInterface
{
    private const XML_PATH_ENABLED = 'insead_checkout/insead_customcheckout/enabled';
    private const HANDLE = 'insead_customcheckout_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($observer->getEvent()->getFullActionName() !== 'checkout_index_index') {
            return;
        }
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return;
        }
        if ($this->isBillingAlreadyConfirmed()) {
            // Billing Information already saved for this quote — let native
            // checkout_index_index.xml render completely untouched so the
            // payment step is genuine, unmodified Magento.
            return;
        }
        $observer->getEvent()->getLayout()->getUpdate()->addHandle(self::HANDLE);
    }

    private function isBillingAlreadyConfirmed(): bool
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            return $quote && $quote->getId() && (int) $quote->getData('insead_billing_confirmed') === 1;
        } catch (\Throwable $e) {
            // Fail safe toward showing the custom Billing Information step
            // rather than accidentally skipping straight to native payment
            // with no billing data collected.
            return false;
        }
    }
}

