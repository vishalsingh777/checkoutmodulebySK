<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\ScopeInterface;

/**
 * The Billing Information step as its own page, at /insead_checkout/billing
 * — a real, distinct URL from /checkout, not the same address wearing two
 * layouts. This is what makes browser back/forward behave normally: there
 * are genuinely two pages now, not one URL whose content silently depends
 * on server-side state.
 *
 * Renders the SAME Block\Checkout + billing-form.phtml as before — nothing
 * about the form itself, its fields, its save logic, or its styling
 * changes. Only where it's hosted changes.
 *
 * Reachable two ways, both landing here:
 *   1. Plugin\RedirectToBillingStepPlugin — redirects /checkout here
 *      whenever the current quote hasn't completed this step yet.
 *   2. Controller\Billing\Edit — "Edit Billing Information" on the native
 *      payment page links straight here.
 *   3. Directly (bookmark, retyped URL, browser history) — always just
 *      re-shows the form, prefilled with whatever was last entered, for
 *      review/edit. It never redirects forward to /checkout on its own;
 *      only explicitly clicking Continue to Payment does that (see
 *      Controller\Billing\Save, unchanged).
 */
class Index implements HttpGetActionInterface
{
    private const XML_PATH_ENABLED = 'insead_checkout/insead_customcheckout/enabled';

    public function __construct(
        private readonly PageFactory $resultPageFactory,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(): ResultInterface
    {
        $quote = $this->checkoutSession->getQuote();

        // Same "cart is empty" guard native checkout applies — this page is
        // now directly reachable/bookmarkable, so it needs its own check
        // rather than relying on native checkout to have already done it.
        if (!$quote->getId() || (int) $quote->getItemsCount() === 0) {
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }

        // This store view has the custom checkout turned off — this page
        // shouldn't be reachable at all here; send anyone who lands on it
        // (e.g. a stale bookmark from a store where it WAS enabled) to
        // native checkout instead of showing a form that doesn't apply.
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout');
            return $resultRedirect;
        }

        // No redirect based on insead_billing_confirmed here, deliberately —
        // landing on this page always shows the (prefilled) form for
        // review/edit, whether or not this quote already completed the
        // step. Moving forward to payment only happens when the shopper
        // explicitly clicks Continue to Payment.
        return $this->resultPageFactory->create();
    }
}
