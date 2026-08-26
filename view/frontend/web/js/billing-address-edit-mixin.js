/**
 * The billing address on this quote was collected by INSEAD's own Billing
 * Information step (country-group Tax & Legal cascade, GST Declaration,
 * company lookup — none of which exist in native Magento's address form).
 * Every enabled payment method (Braintree, Stripe, PayPal, etc.) shows its
 * own "Edit" toggle next to the billing address summary once a valid
 * address exists on the quote — confirmed via direct inspection to be a
 * checkbox-style toggle (class "edit-address", paired with a hidden
 * checkbox input via isCheckboxChecked/checkboxChange), rendered by
 * whichever payment method's own component is currently active — NOT a
 * single shared element owned by Magento_Checkout/js/view/billing-address
 * itself, despite that being the component this mixin attaches to. That
 * toggle leads to Magento's STOCK address form, which has no knowledge of
 * any of the above and would let someone silently change the address
 * without touching the fields it depends on (tax status, VAT/registration
 * numbers, GST declaration, etc.).
 *
 * Per explicit instruction: this must not show at all on quotes that went
 * through our step — not just redirect elsewhere when clicked. Detected via
 * window.checkoutConfig.inseadBillingConfirmed (see
 * Plugin\UseQuoteBillingAddressPlugin::afterGetConfig), falling back to the
 * presence of the "Edit Billing Information" link (`.insead-payment-extras`)
 * if that config value is somehow unavailable. On any other quote/store —
 * this module's flow was never used — nothing here does anything, and
 * native behavior is completely untouched.
 *
 * Two layers, since which payment method is selected/expanded (and
 * therefore which component actually renders this toggle) can change after
 * page load:
 *   1. Hide it outright via the confirmed real selector (.edit-address),
 *      re-run on every DOM change via MutationObserver — since each payment
 *      method renders its own copy independently, at unpredictable times,
 *      switching between them can't be caught by a single fixed-delay retry.
 *   2. Fallback safety net: even if hiding missed (unrecognized theme/
 *      payment-method markup), a click must NEVER open Magento's stock
 *      form — redirect to our own edit page instead.
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
        // Confirmed via direct page inspection: this is a <label
        // class="action primary edit-address"> toggling a paired hidden
        // checkbox (isCheckboxChecked/checkboxChange), rendered by the
        // active payment method's own component (e.g. Braintree — the
        // element's id includes "...payments-list.braintree"), NOT by
        // Magento_Checkout/js/view/billing-address itself. It doesn't live
        // under .box-billing-address, so that scoping was also wrong — this
        // targets the real class directly instead, plus the earlier guesses
        // as harmless no-op fallbacks in case another theme/payment method
        // genuinely uses one of them.
        var labelSelectors = ['.edit-address', '.action-edit-address', '.action-edit'];
        labelSelectors.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                // Hide the whole wrapping container (checkbox + label
                // together, e.g. <div class="field-checkbox ...">), not
                // just the label — so the checkbox itself is also removed
                // from keyboard/assistive-tech reach, not merely invisible
                // text next to a still-focusable control.
                var container = el.closest('.field-checkbox') || el.parentElement || el;
                container.style.display = 'none';
            });
        });
    }

    return function (BillingAddress) {
        return BillingAddress.extend({
            initialize: function () {
                this._super();
                if (isInseadFlowQuote()) {
                    hideNativeEditButton();
                    // This checkbox belongs to whichever payment method is
                    // currently selected/expanded (Braintree, Stripe, PayPal,
                    // etc. each render their own independently) — switching
                    // payment methods re-renders it at a time a single fixed
                    // retry can't reliably predict. A MutationObserver on the
                    // whole payment area re-runs the hide check on every DOM
                    // change instead, for as long as this page exists.
                    var target = document.querySelector('.opc-payment')
                        || document.querySelector('.opc-wrapper')
                        || document.body;
                    if (target && window.MutationObserver) {
                        new MutationObserver(hideNativeEditButton)
                            .observe(target, { childList: true, subtree: true });
                    } else {
                        // Fallback for a browser without MutationObserver
                        // (shouldn't happen in any supported browser today).
                        setTimeout(hideNativeEditButton, 500);
                        setTimeout(hideNativeEditButton, 1500);
                    }
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