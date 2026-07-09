/**
 * The billing address on this quote was collected by INSEAD's own Billing
 * Information step (country-group Tax & Legal cascade, GST Declaration,
 * company lookup — none of which exist in native Magento's address form).
 * Native checkout's own billing-address component still shows a read-only
 * summary of that address with its own "Edit" link once the quote already
 * has a valid one — but that link opens Magento's STOCK address form, which
 * has no knowledge of any of the above and would let someone silently
 * change the address without touching the fields it depends on (tax status,
 * VAT/registration numbers, GST declaration, etc.).
 *
 * This mixin overrides editAddress() so that click goes to our own
 * "Edit Billing Information" link instead (see Block\PaymentPageExtras /
 * Controller\Billing\Edit) — reading the URL straight off that link's own
 * markup rather than duplicating URL-building logic here.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return function (BillingAddress) {
        return BillingAddress.extend({
            editAddress: function () {
                var link = document.querySelector('.insead-payment-extras a.insead-link');
                if (link && link.href) {
                    window.location.href = link.href;
                    return;
                }
                // Fallback: our link should always be present when this quote
                // went through the custom Billing Information step, but if
                // it's somehow missing, don't leave the click doing nothing —
                // fall back to native's own behavior rather than silently
                // failing.
                this._super();
            }
        });
    };
});
