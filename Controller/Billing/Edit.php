<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * "Edit Billing Information" — sends the browser to the Billing Information
 * step's own page (Controller\Billing\Index), which always shows the form
 * for review/edit regardless of whether this quote already completed it.
 *
 * Doesn't touch insead_billing_confirmed. That flag's only remaining job is
 * deciding whether GET /checkout should redirect to billing first (see
 * Plugin\RedirectToBillingStepPlugin) — and this link bypasses that check
 * entirely by going straight to the billing URL. Clearing the flag here
 * would only cause an extra, unnecessary bounce back to billing later if
 * the shopper abandons this edit without saving; only an actual save (see
 * Controller\Billing\Save, unchanged) should change what the flag reflects.
 *
 * Linked from Block\PaymentPageExtras, which is added to native
 * checkout_index_index.xml alongside checkout.root (not replacing it) — see
 * that block/layout for where this link actually appears.
 */
class Edit implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $resultRedirectFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('insead_checkout/billing');
        return $resultRedirect;
    }
}
