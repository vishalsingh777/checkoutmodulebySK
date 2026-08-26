/**
 * The billing address on this quote was collected by INSEAD's own Billing
 * Information step (country-group Tax & Legal cascade, GST Declaration,
 * company lookup — none of which exist in native Magento's address form).
 * Native checkout's own billing-address component still shows a read-only
 * summary of that address with its own "Edit" button once the quote already
 * has a valid one — but that button opens Magento's STOCK address form,
 * which has no knowledge of any of the above and would let someone silently
 * change the address without touching the fields it depends on (tax status,
 * VAT/registration numbers, GST declaration, etc.).
 *
 * Per explicit instruction: this must not show at all on quotes that went
 * through our step — not just redirect elsewhere when clicked. Detected via
 * the presence of the "Edit Billing Information" link (`.insead-payment-extras`),
 * which Block\PaymentPageExtras only ever renders when
 * insead_billing_confirmed=1 for this quote (see that class's shouldShow()).
 * On any other quote/store — this module's flow was never used — nothing
 * here does anything, and native behavior is completely untouched.
 *
 * Two layers, since the exact CSS class native checkout's Edit button uses
 * can vary by theme (same class-name uncertainty documented for the header
 * cleanup earlier in this project — see that work for how to verify/adjust
 * if these selectors don't match this theme):
 *   1. Hide it outright via the selectors below (standard Luma class names).
 *   2. Fallback safety net: even if hiding missed (unrecognized theme
 *      markup), a click must NEVER open Magento's stock form — redirect to
 *      our own edit page instead, same as this mixin did previously.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /**
     * Prefers the real per-quote flag from window.checkoutConfig
     * (see Plugin\UseQuoteBillingAddressPlugin::afterGetConfig) — set
     * server-side from the quote's own insead_billing_confirmed column, the
     * precise thing that actually matters here (did THIS quote's billing
     * address come from our form), not just whether the module is turned on
     * for the store in general. Falls back to checking for the "Edit
     * Billing Information" block in the DOM only if that config value is
     * somehow unavailable (e.g. an older deploy without the plugin change).
     */
    function isInseadFlowQuote() {
        if (window.checkoutConfig && window.checkoutConfig.inseadBillingConfirmed) {
            return true;
        }
        return !!document.querySelector('.insead-payment-extras');
    }

    function hideNativeEditButton() {
        var selectors = [
            '.box-billing-address .action-edit-address',
            '.box-billing-address .action-edit',
            '.box-billing-address [data-bind*="editAddress"]'
        ];
        selectors.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.style.display = 'none';
            });
        });
    }

    return function (BillingAddress) {
        return BillingAddress.extend({
            initialize: function () {
                this._super();
                if (isInseadFlowQuote()) {
                    // The billing-address summary can render/re-render after
                    // this component initializes (e.g. once totals/address
                    // data finishes loading), so a single immediate hide
                    // attempt can run before the button exists yet. A short
                    // delayed retry covers that without needing to hook this
                    // theme's exact render-complete event.
                    hideNativeEditButton();
                    setTimeout(hideNativeEditButton, 500);
                }
                return this;
            },

            editAddress: function () {
                if (isInseadFlowQuote()) {
                    var link = document.querySelector('.insead-payment-extras a.insead-link');
                    if (link && link.href) {
                        window.location.href = link.href;
                        return;
                    }
                }
                this._super();
            }
        });
    };
});