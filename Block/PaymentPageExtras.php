<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Small block ADDED alongside native checkout's payment step (see
 * view/frontend/layout/checkout_index_index.xml — an unconditional layout
 * update, unlike insead_customcheckout_enabled.xml, which only ever ADDS a
 * block into the `content` container rather than removing checkout.root).
 *
 * Surfaces the one thing that doesn't have a native-Magento equivalent and
 * previously lived on the custom payment step: the Proforma Invoice
 * ("Quotation") button, plus a link back to the custom Billing Information
 * step. Declarations are NOT duplicated here — native checkout's own page
 * already surfaces that. Only renders at all when this quote actually went
 * through the custom Billing Information step (insead_billing_confirmed = 1)
 * — never on a store where that flow isn't used, and never on the Billing
 * Information page itself, only on the following native payment page.
 */
class PaymentPageExtras extends Template
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** Only show once billing has been confirmed for this quote via our step. */
    public function shouldShow(): bool
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            return (bool) ($quote && $quote->getId() && (int) $quote->getData('insead_billing_confirmed') === 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getEditBillingUrl(): string
    {
        return $this->getUrl('insead_checkout/billing/edit');
    }

    public function isProformaInvoiceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'proforma_invoice/general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getProformaPdfUrl(): string
    {
        return $this->getUrl('proforma/quote/pdf');
    }
}

