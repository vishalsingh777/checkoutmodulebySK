<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Plugin;

use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Every existing "Checkout" link/button on the site keeps pointing at
 * /checkout, unchanged — this plugin is what quietly routes a shopper to
 * the Billing Information step first when needed, so nothing sitewide had
 * to be hunted down and updated to a new URL.
 *
 * Wraps native checkout's own controller (not an event/layout hook) so the
 * decision happens before native does any work at all: if this store has
 * the custom step enabled and the current quote hasn't completed it yet —
 * or looks like it did (insead_billing_confirmed = 1) but the billing
 * address has since gone missing (see hasRealBillingAddress()) — redirect
 * to /insead_checkout/billing and never let native's own execute() run.
 * Otherwise, call straight through — native checkout renders exactly as it
 * does today, with no involvement from this module.
 *
 * Replaces the old Observer\AddCustomCheckoutLayoutHandle /
 * insead_customcheckout_enabled.xml layout-swap approach, which put both
 * pages behind the SAME /checkout URL — the actual cause of browser
 * back/forward problems that approach had.
 */
class RedirectToBillingStepPlugin
{
    private const XML_PATH_ENABLED = 'insead_checkout/insead_customcheckout/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * No return type declared, deliberately. Magento HTTP action
     * controllers (including native checkout's) can legitimately return
     * ResultInterface|ResponseInterface depending on code path — declaring
     * a strict return type here, combined with this file's
     * declare(strict_types=1), would mean PHP throws a fatal TypeError the
     * moment $proceed() returns anything that isn't strictly the declared
     * type, taking down every single /checkout load. Passing through
     * whatever native returns, untyped, is the safe pattern for wrapping a
     * controller's execute() this way.
     */
    public function aroundExecute(CheckoutIndex $subject, callable $proceed)
    {
        if ($this->shouldRedirectToBilling()) {
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('insead_checkout/billing');
            return $resultRedirect;
        }
        return $proceed();
    }

    private function shouldRedirectToBilling(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || (int) $quote->getItemsCount() === 0) {
                // Empty/missing cart — let native checkout's own execute()
                // handle that (it redirects to the cart page itself); this
                // plugin only decides between "billing" and "native payment",
                // not the empty-cart case.
                return false;
            }
            if ((int) $quote->getData('insead_billing_confirmed') !== 1) {
                return true;
            }

            // The flag says billing was confirmed at some point — but that's
            // history, not current fact. Something can legitimately wipe the
            // quote's address after that without ever touching this flag:
            // Ewave's own populate() controller unconditionally calls
            // removeAddress()/removeAllItems() and rebuilds from whatever
            // that specific request carries (e.g. a lighter "just add this
            // SKU" call with no address data), and other add-to-cart paths
            // aren't guaranteed to preserve it either. Rather than try to
            // catch every possible path that could do this, check the
            // actual current state right here: if the billing address no
            // longer has real data, treat this as NOT confirmed regardless
            // of what the flag says, so the customer lands back on billing
            // instead of a blank native checkout.
            return !$this->hasRealBillingAddress($quote);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout billing-step redirect check: ' . $e->getMessage());
            // Fail safe toward native checkout rather than risking a
            // redirect loop if something above is unexpectedly broken.
            return false;
        }
    }

    /**
     * Minimum fields a genuinely-completed billing address must have.
     * Deliberately checks the CURRENT quote object, not a cached/stale one —
     * this runs right before the redirect decision, on the same request.
     */
    private function hasRealBillingAddress(Quote $quote): bool
    {
        $billing = $quote->getBillingAddress();
        if (!$billing || !$billing->getId()) {
            return false;
        }
        $street = $billing->getStreet();
        $hasStreet = !empty($street) && trim((string) (is_array($street) ? ($street[0] ?? '') : $street)) !== '';

        return trim((string) $billing->getFirstname()) !== ''
            && trim((string) $billing->getLastname()) !== ''
            && $hasStreet
            && trim((string) $billing->getCity()) !== ''
            && trim((string) $billing->getCountryId()) !== '';
    }
}
