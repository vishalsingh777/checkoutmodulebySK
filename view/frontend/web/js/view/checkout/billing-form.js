/**
 * INSEAD Custom Checkout — Billing Information step (Self-funded/B2C or
 * Sponsored/Organisation/B2B) with country-group x tax-status driven Tax &
 * Legal fields and a GST Declaration.
 *
 * This is now the ONLY step this component renders. On success, Continue to
 * Payment (proceedToPayment) redirects the browser to the URL the server
 * returns — genuine, unmodified native Magento checkout — rather than
 * switching to an in-page payment step. See Controller\Billing\Save and
 * Observer\AddCustomCheckoutLayoutHandle for the handoff mechanism. This
 * means every enabled payment method (Stripe, Braintree, PayPal,
 * Sogecommerce, Check/Money Order, Cash On Delivery, anything enabled later)
 * is handled entirely by native Magento, with zero payment-method-specific
 * code in this file.
 */
define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'mage/cookies'
], function (Component, ko, $, $t, alert) {
    'use strict';

    // EU member-state ISO codes (France is its own group, handled separately
    // below for SIRET/SIREN). GB included per spec; EL is the official ISO for Greece.
    var euCountries = [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','DE',
        'EL','GB','HU','IE','IT','LV','LT','LU','MT','NL','PL',
        'PT','RO','SK','SI','ES','SE'
    ];

    // Map any ISO 2-letter country code to a billing group: FR | SG | UAE | US | EU | ROW.
    function countryToGroup(iso) {
        if (iso === 'FR') { return 'FR'; }
        if (iso === 'SG') { return 'SG'; }
        if (iso === 'AE') { return 'UAE'; }
        if (iso === 'US') { return 'US'; }
        if (euCountries.indexOf(iso) !== -1) { return 'EU'; }
        return 'ROW';
    }

    /**
     * Validates a French SIRET number: exactly 14 digits, passing the Luhn
     * (mod 10) checksum used for SIRET/SIREN — the standard offline
     * verification (no external service exists for this the way VIES does
     * for EU VAT numbers). Note: French *La Poste* SIRET numbers are a
     * documented, long-standing exception to the Luhn check; not handled
     * here, matching how most public SIRET validators behave.
     */
    function isValidSiret(value) {
        var digits = (value || '').replace(/\s+/g, '');
        if (!/^\d{14}$/.test(digits)) { return false; }
        var sum = 0;
        for (var i = 0; i < digits.length; i++) {
            var digit = parseInt(digits.charAt(digits.length - 1 - i), 10);
            if (i % 2 === 1) {
                digit *= 2;
                if (digit > 9) { digit -= 9; }
            }
            sum += digit;
        }
        return sum % 10 === 0;
    }

    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    var taxLabels = {
        reg: $t('Tax Registered'),
        notreg: $t('Not Tax Registered'),
        exempt: $t('Tax Exempt')
    };

    // Tax-status options available per country group. FR/SG get all three, UAE
    // gets two; US/EU/ROW have only "Tax Registered" (so the dropdown is hidden —
    // there is no choice to make).
    var taxOptions = {
        FR:  ['reg', 'notreg', 'exempt'],
        SG:  ['reg', 'notreg', 'exempt'],
        UAE: ['reg', 'notreg'],
        US:  ['reg'],
        EU:  ['reg'],
        ROW: ['reg']
    };

    // Gender options for the Self-funded customer-information section.
    var genderOptions = [
        {value: 'male', label: $t('Male')},
        {value: 'female', label: $t('Female')},
        {value: 'nonbinary', label: $t('Non binary')},
        {value: 'prefer_not', label: $t('Prefer not to answer')}
    ];

    // Region cache keyed by ISO country code (avoids repeat directory calls).
    var regionCache = {};

    // Dynamic Tax & Legal Information matrix (spec: tax-fields-changelogic md).
    // Each entry maps to a quote column "code"; type: req | opt | file.
    var DUNS     = {code: 'duns_number',    label: 'D-U-N-S Number (optional)',       type: 'opt',
        tooltip: 'A unique nine-digit identifier issued by Dun & Bradstreet used to identify your business globally.'};
    var PO       = {code: 'po_number',      label: 'PO Number',                       type: 'opt',
        tooltip: 'Please check with your procurement/accounting if needed for invoicing.'};
    var ROUTING  = {code: 'routing_address',label: 'Routing Address',                 type: 'opt',
        tooltip: 'The address invoices should be routed to within your organisation, if different from the postal address above.'};
    var TAXREG   = {code: 'tax_id_number',  label: 'Tax Registration Number',         type: 'opt',
        tooltip: "Your company's official tax registration number issued by the local tax authority."};
    var CERT_OPT = {code: 'certificate_id', label: 'Certificate ID',                  type: 'opt',
        tooltip: 'Reference number of the certificate supporting your tax-exempt status.'};
    var CERT_REQ = {code: 'certificate_id', label: 'Certificate ID',                  type: 'req',
        tooltip: 'Reference number of the certificate supporting your tax-exempt status.'};
    var UPLOAD     = {code: 'tax_exempt_file',label: 'Upload Tax Exempt File',        type: 'file',
        tooltip: 'Upload a copy of your valid tax exemption certificate (PDF or image).'};
    var UPLOAD_REQ = {code: 'tax_exempt_file',label: 'Upload Tax Exempt File',        type: 'file_req',
        tooltip: 'Upload a copy of your valid tax exemption certificate (PDF or image).'};
    var VATUAE   = {code: 'vat_uae',        label: 'UAE VAT Number',                  type: 'req',
        tooltip: "Your company's UAE VAT registration number, issued by the Federal Tax Authority."};
    var TRN      = {code: 'trn',             label: 'TRN',                             type: 'req',
        tooltip: 'Tax Registration Number issued by the UAE Federal Tax Authority.'};
    var EIN      = {code: 'ein',             label: 'EIN',                             type: 'req',
        tooltip: "Your company's Employer Identification Number issued by the US IRS."};
    var REG_LABEL= {code: 'reg_type_label', label: 'Registration Type',               type: 'opt',
        tooltip: 'The type of business registration number your company holds (e.g. EIN, DUNS).'};
    var REG_VALUE= {code: 'reg_type_value', label: 'Registration Type Value',         type: 'opt',
        tooltip: 'The value of the registration number selected above.'};
    var VAT_IC   = {code: 'vat_intracommunity', label: 'VAT Intracommunity Number',   type: 'req',
        tooltip: "Your company's Intracommunity VAT number, validated against the EU VIES database."};
    var UEN_SG   = {code: 'uen', label: 'Business Registration Number (UEN)',         type: 'req',
        tooltip: "Your company's Unique Entity Number issued by the Singapore government."};
    var UEN_GEN  = {code: 'uen', label: 'Business Registration Number',               type: 'req',
        tooltip: 'Official business registration or tax identification number issued by the relevant government authority in the selected country.'};
    var GST_NUM  = {code: 'gst_number', label: 'GST Number',                          type: 'req',
        tooltip: "Your company's Goods & Services Tax registration number."};

    var fieldDefs = {
        // France: SIRET/SIREN handled separately in Organisation Details.
        // reg_type_label/value are auto-set from SIRET in _collectPayload — not dynamic fields.
        FR_reg:    [DUNS, VAT_IC,   PO, ROUTING],
        FR_notreg: [DUNS,           PO, ROUTING],
        FR_exempt: [CERT_REQ, DUNS, PO, ROUTING, UPLOAD_REQ],

        SG_reg:    [UEN_SG, DUNS, GST_NUM, PO],
        SG_notreg: [UEN_SG, DUNS,          PO],
        SG_exempt: [CERT_REQ, DUNS,        PO, UPLOAD_REQ],

        // UAE has only Reg and Not-Reg (no Exempt option per spec).
        // Business Registration Number (BRN) required for UAE per spec.
        UAE_reg:    [TRN, DUNS, VATUAE, PO],
        UAE_notreg: [TRN, DUNS,         PO],
        UAE_exempt: [CERT_REQ, DUNS,        PO, ROUTING],   // unreachable; no UPLOAD per spec

        // US: no national VAT/GST equivalent.
        US_reg:    [EIN, DUNS, TAXREG, PO],
        US_notreg: [UEN_GEN, DUNS, TAXREG, PO, ROUTING, REG_LABEL, REG_VALUE, CERT_OPT],
        US_exempt: [CERT_REQ, DUNS, TAXREG, PO, ROUTING],   // unreachable; no UPLOAD per spec

        // Business Registration Number (BRN) required for EU per spec, alongside VAT-IC.
        // EU_reg drops Routing Address/Registration Type/Registration Type
        // Value/Certificate ID — not applicable once the company is Tax Registered.
        EU_reg:    [UEN_GEN, DUNS, VAT_IC, PO],
        EU_notreg: [UEN_GEN, DUNS,         PO, ROUTING, REG_LABEL, REG_VALUE, CERT_OPT],
        EU_exempt: [CERT_REQ, DUNS, PO, ROUTING],            // unreachable; no UPLOAD per spec

        // ROW_reg drops Routing Address/Registration Type/Registration Type
        // Value/Certificate ID — not applicable once the company is Tax Registered.
        ROW_reg:    [UEN_GEN, DUNS, TAXREG, PO],
        ROW_notreg: [UEN_GEN, DUNS, TAXREG, PO, ROUTING, REG_LABEL, REG_VALUE, CERT_OPT],
        ROW_exempt: [CERT_REQ, DUNS, TAXREG, PO, ROUTING]   // unreachable; no UPLOAD per spec
    };

    // Every quote column any dynamic Tax & Legal field (across all
    // country-group x tax-status combinations above) can write. Used by
    // _collectPayload() to blank out columns that fall out of scope on a
    // country/tax-status change — without this, a value from a field that's
    // no longer shown (e.g. `uen`/`reg_type_label` auto-filled from a France
    // SIRET) is simply omitted from the next save's payload, and
    // Controller\Billing\Save only overwrites a column when its key is
    // present in the request — so the stale value silently survives on the
    // quote/order forever instead of being cleared.
    var allDynamicFieldCodes = [
        'duns_number', 'po_number', 'routing_address', 'tax_id_number',
        'certificate_id', 'tax_exempt_file', 'vat_uae', 'trn', 'ein',
        'reg_type_label', 'reg_type_value', 'vat_intracommunity', 'uen', 'gst_number'
    ];

    // Custom quote columns that only ever apply to Sponsored/B2B billing.
    // Sent blank whenever the shopper is on the B2C branch, for the same
    // reason as allDynamicFieldCodes above — so switching B2B -> B2C on the
    // same quote clears the earlier B2B organisation/tax data instead of
    // leaving it stranded.
    var b2bOnlyColumns = [
        'tax_registration_status', 'company_legal_name', 'commercial_company_name',
        'organization_type', 'job_industry', 'gst_declaration',
        'invoice_recipient_firstname', 'invoice_recipient_lastname', 'invoice_email'
    ].concat(allDynamicFieldCodes);

    // INSEAD organisation type + job industry options.
    var organizationTypes = [
        'Embassy', 'Sole Proprietorship', 'Business', 'Family Business', 'Foundation',
        'Intergovernmental Organization', 'Listed Company', 'Non-Profit', 'Private Organization',
        'Professional Firm', 'School', 'State Owned Organization'
    ];

    var jobIndustries = [
        'Accounting', 'Agriculture', 'Airlines/Aviation', 'Architecture & Planning', 'Automotive',
        'Banking', 'Biotechnology', 'Capital Markets', 'Chemicals', 'Computer Software',
        'Construction', 'Consulting', 'Consumer Goods', 'Education Management', 'Financial Services',
        'Government Administration', 'Health, Wellness and Fitness', 'Higher Education',
        'Hospital & Health Care', 'Hospitality', 'Human Resources', 'Information Technology and Services',
        'Insurance', 'Investment Banking', 'Investment Management', 'Legal Services', 'Logistics and Supply Chain',
        'Management Consulting', 'Manufacturing', 'Marketing and Advertising', 'Oil & Energy',
        'Pharmaceuticals', 'Private Equity', 'Real Estate', 'Renewables & Environment', 'Research',
        'Retail', 'Telecommunications', 'Transportation/Trucking/Railroad', 'Utilities',
        'Venture Capital', 'Other'
    ];

    // GST Declaration statements (Rest-of-World, programme in Singapore).
    var gstStatements = [
        {value: '1', label: 'We do NOT have any branch, agency, office, factory, warehouse or personnel in Singapore.'},
        {value: '2', label: 'We have a branch, agency, office, factory, warehouse or personnel in Singapore, BUT the services rendered by INSEAD will NOT be used directly by our business or fixed establishment in Singapore.'},
        {value: '3', label: 'One or more of the participants have their usual place of residence in Singapore, BUT they are employed by the GST-registered Singaporean entity (or entities) below:'},
        {value: '4', label: 'None of the above statements apply to our company (GST applies)'}
    ];

    return Component.extend({
        defaults: {
            template: 'Insead_CustomCheckout/checkout/billing-form',
            // Populated from the x-magento-init node (spread at top level).
            isLoggedIn: false,
            customer: {},
            countries: [],
            prefill: {},
            gstEnabled: false,
            showGenderFieldB2c: true,
            showGenderFieldB2b: true,
            showNationalityFieldB2c: true,
            showNationalityFieldB2b: true,
            phoneRequiredB2c: true,
            phoneRequiredB2b: false,
            urls: {},
            formKey: ''
        },

        initialize: function () {
            this._super();
            this.taxLabels = taxLabels;
            this.organizationTypes = organizationTypes;
            this.jobIndustries = jobIndustries;
            this.gstStatements = gstStatements;
            this.genderOptions = genderOptions;
            this.today = new Date().toLocaleDateString();
            this._initObservables();
            this._initComputed();
            this._initSubscriptions();
            this.renderFields();
            this._loadRegions(this.country());
            return this;
        },

        _initObservables: function () {
            var customer = this.customer || {};
            // Prefill payload from the quote the Ewave populate flow already filled.
            var pf = this.prefill || {};
            var taxStatuses = ['reg', 'notreg', 'exempt'];
            var isFR = (pf.country_id === 'FR');

            // UI state — created first: valueFor()/_clearOnInput() (used below,
            // including while pre-seeding dynamic fields) call clearError(),
            // which reads/writes these.
            this.errors = ko.observable({});
            this.globalError = ko.observable('');

            this.endpointUrls = this.urls || {};
            this.formKeyValue = this.formKey || '';
            this.countriesList = ko.observableArray(this.countries || []);
            this.gstEnabled = !!this.gstEnabled;
            this.showGenderFieldB2c = !!this.showGenderFieldB2c;
            this.showGenderFieldB2b = !!this.showGenderFieldB2b;
            this.showNationalityFieldB2c = !!this.showNationalityFieldB2c;
            this.showNationalityFieldB2b = !!this.showNationalityFieldB2b;
            this.phoneRequiredB2c = !!this.phoneRequiredB2c;
            this.phoneRequiredB2b = !!this.phoneRequiredB2b;

            // Passthrough values from the populate flow — not editable in the form
            // but must round-trip to Save.php so they persist on the quote/customer.
            this._peoplesoftId = pf.peoplesoft_id || '';

            // Billing-address identity (name/email). Supplied by the upstream
            // flow and not shown for Self-funded; used server-side for the address.
            this.customerFirstName = pf.firstname || customer.firstname || '';
            this.customerLastName = pf.lastname || customer.lastname || '';
            this.customerEmail = pf.email || customer.email || '';
            // Phone pre-fills from the upstream flow but is editable for B2B.
            this.customerTelephone = ko.observable(pf.telephone || '');

            // ---- Selector box ----
            this.country = ko.observable(pf.country_id || 'FR'); // ISO code
            this.financingProfile = ko.observable(pf.is_btob ? 'b2b' : 'b2c'); // b2c = Self-funded
            this.taxStatus = ko.observable(
                taxStatuses.indexOf(pf.tax_registration_status) !== -1 ? pf.tax_registration_status : 'reg'
            );
            this.taxStatusList = ko.observableArray([]);

            // ---- B2C (Self-funded) — customer information ----
            this.b2cFirstName = ko.observable(pf.firstname || customer.firstname || '');
            this.b2cLastName = ko.observable(pf.lastname || customer.lastname || '');
            this.b2cEmail = ko.observable(pf.email || customer.email || '');
            this.gender = ko.observable(pf.gender || '');
            this.b2cPhone = ko.observable(pf.telephone || '');
            this.nationality = ko.observable(pf.nationality || pf.country_id || '');

            // ---- B2C (Self-funded) — billing address ----
            this.street1 = ko.observable(pf.street1 || '');
            this.street2 = ko.observable(pf.street2 || '');
            this.city = ko.observable(pf.city || '');
            this.region = ko.observable(pf.region || '');
            this.postcode = ko.observable(pf.postcode || '');
            this.residencyDeclaration = ko.observable(pf.residency_declaration || '');

            // ---- B2B (Sponsored) — Organisation details ----
            this.invoiceFirstName = ko.observable(pf.invoice_recipient_firstname || pf.firstname || '');
            this.invoiceLastName = ko.observable(pf.invoice_recipient_lastname || pf.lastname || '');
            this.invoiceEmail = ko.observable(pf.invoice_email || pf.email || '');
            this.companyLegalName = ko.observable(pf.company_legal_name || pf.company || '');
            this.companyTradeName = ko.observable(pf.commercial_company_name || '');
            this.organizationType = ko.observable(pf.organization_type || '');
            this.jobIndustry = ko.observable(pf.job_industry || '');
            this.isCompanyNameAuto = ko.observable(false);
            this.siret = ko.observable(isFR ? (pf.uen || '') : '');
            this.siren = ko.observable('');
            // Live SIRET checksum feedback: idle | valid | invalid.
            this.siretStatus = ko.observable('idle');
            this.siretMessage = ko.observable('');

            // Live VAT Intracommunity Number feedback (VIES, via core Magento
            // Magento\Customer\Model\Vat — see Controller\Vat\Validate):
            // idle | checking | valid | invalid | unknown.
            this.vatStatus = ko.observable('idle');
            this.vatMessage = ko.observable('');

            // ---- B2B — Postal address (shares the populated billing address) ----
            this.companyStreet1 = ko.observable(pf.street1 || '');
            this.companyStreet2 = ko.observable(pf.street2 || '');
            this.companyCity = ko.observable(pf.city || '');
            this.companyState = ko.observable(pf.region || '');
            this.companyZip = ko.observable(pf.postcode || '');

            // Region/state dropdown (shared — both profiles use the same country).
            this.regionsList = ko.observableArray([]);

            // Dynamic Tax & Legal fields. Entered values persist across re-renders.
            this.dynamicFields = ko.observableArray([]);
            this.fieldValues = {};
            // Pre-seed dynamic field values from the prefill (skip FR's uen — that
            // maps to SIRET in Organisation Details, not Tax & Legal).
            var seed = {
                vat_intracommunity: pf.vat_intracommunity, gst_number: pf.gst_number,
                vat_uae: pf.vat_uae,
                tax_id_number: pf.tax_id_number, certificate_id: pf.certificate_id,
                duns_number: pf.duns_number, routing_address: pf.routing_address,
                po_number: pf.po_number,
                reg_type_label: pf.reg_type_label, reg_type_value: pf.reg_type_value,
                // Already-uploaded server path from a prior save; resubmitted as-is
                // by _collectPayload() unless the shopper picks a new file (which
                // populates _taxExemptPaths and takes priority — see _collectPayload).
                tax_exempt_file: pf.tax_exempt_file
            };
            if (!isFR) { seed.uen = pf.uen; }
            for (var code in seed) {
                // Route through valueFor() (not a raw ko.observable(...) assignment)
                // so these pre-seeded fields get the same live error-clearing
                // subscription as fields created lazily during renderFields().
                if (seed.hasOwnProperty(code)) { this.valueFor(code)(seed[code] || ''); }
            }

            // GST Declaration.
            this.gstDeclaration = ko.observable(pf.gst_declaration || '');

            this.isLoading = ko.observable(false);
        },

        _initComputed: function () {
            var self = this;
            this.isB2B = ko.computed(function () { return self.financingProfile() === 'b2b'; });
            this.isB2C = ko.computed(function () { return self.financingProfile() === 'b2c'; });
            this.countryGroup = ko.computed(function () { return countryToGroup(self.country()); });
            this.isFrance = ko.computed(function () { return self.country() === 'FR'; });
            // Tax Status dropdown: shown for all B2B country groups. FR/SG/UAE offer
            // a real choice; US/EU/ROW show the single "Tax Registered" option.
            this.showTaxStatus = ko.computed(function () {
                return self.isB2B();
            });
            this.hasRegions = ko.computed(function () { return self.regionsList().length > 0; });
            // GST Declaration: B2B, programme in Singapore, billing address
            // anything OTHER than Singapore itself (a Singapore-billing B2B
            // customer never needs the declaration — they're already inside
            // the GST net).
            this.showGstDeclaration = ko.computed(function () {
                return self.isB2B() && self.gstEnabled
                    && self.countryGroup() !== 'SG';
            });
            // Self-funded residency Declaration: Singapore legal entity AND
            // B2C AND billing country anything OTHER than Singapore itself.
            this.showResidencyDeclaration = ko.computed(function () {
                return self.isB2C() && self.gstEnabled
                    && self.country() !== 'SG';
            });
            // Blocks "Continue to Payment" while the VAT Intracommunity Number
            // field is on screen (FR/EU, Tax Registered) and either mid-check or
            // confirmed invalid by VIES. A field that isn't currently rendered/
            // required (e.g. Not Registered) never blocks submission.
            //
            // Gated on isB2B(): dynamicFields() is built purely from
            // countryGroup()+taxStatus() and isn't reset when the shopper
            // switches B2B -> B2C (country/tax status carry over), so without
            // this guard, a leftover 'checking'/'invalid' vatStatus from an
            // earlier B2B France/EU selection kept the button disabled forever
            // after switching to B2C, even though the VAT field is never shown
            // there at all.
            this.vatBlocksSubmit = ko.computed(function () {
                if (!self.isB2B()) { return false; }
                var field = self.dynamicFields().filter(function (f) {
                    return f.code === 'vat_intracommunity';
                })[0];
                if (!field || !field.required || !field.value()) { return false; }
                return self.vatStatus() === 'invalid' || self.vatStatus() === 'checking';
            });
        },

        _initSubscriptions: function () {
            var self = this;
            // Country / Billing Profile / Tax Status all reshape which fields are
            // shown and required (see renderFields), so any validation error from
            // before the change is no longer meaningful — clear both the banner
            // and the per-field highlights rather than leaving stale errors up.
            this.country.subscribe(function (val) {
                self.globalError('');
                self.errors({});
                self._loadRegions(val);
                self.renderFields();
                // VAT validity is country-dependent (VIES checks the number
                // against the given country code) — re-check on country change.
                self._scheduleVatValidation();
            });
            this.taxStatus.subscribe(function () {
                self.globalError('');
                self.errors({});
                self.renderFields();
            });
            // When switching B2C ↔ B2B: refresh dynamic fields and reset the GST
            // declaration so stale B2B state never leaks into B2C validation.
            this.financingProfile.subscribe(function () {
                self.globalError('');
                self.errors({});
                self.renderFields();
                self.gstDeclaration('');
            });

            // Per-field: clear a required-field error/highlight the moment the
            // shopper enters an acceptable value, rather than waiting for the
            // next full validateBilling() run (i.e. the next submit attempt).
            [
                ['b2cFirstName', this.b2cFirstName], ['b2cLastName', this.b2cLastName],
                ['b2cEmail', this.b2cEmail, function (v) { return emailRe.test(v || ''); }],
                ['gender', this.gender], ['nationality', this.nationality],
                ['street1', this.street1], ['city', this.city],
                ['residencyDeclaration', this.residencyDeclaration],
                ['invoiceFirstName', this.invoiceFirstName], ['invoiceLastName', this.invoiceLastName],
                ['invoiceEmail', this.invoiceEmail, function (v) { return emailRe.test(v || ''); }],
                ['siret', this.siret, isValidSiret],
                ['companyLegalName', this.companyLegalName], ['organizationType', this.organizationType],
                ['jobIndustry', this.jobIndustry], ['companyStreet1', this.companyStreet1],
                ['companyCity', this.companyCity], ['gstDeclaration', this.gstDeclaration]
            ].forEach(function (entry) { self._clearOnInput(entry[1], entry[0], entry[2]); });

            // Phone only: full bidirectional live validation — turns red
            // immediately if cleared back out while required, not just
            // cleared when filled in (see _liveValidate for why this is
            // scoped to Phone rather than every field).
            self._liveValidate(this.b2cPhone, 'b2cPhone', {
                isRequired: function () { return self.phoneRequiredB2c; }
            });
            self._liveValidate(this.customerTelephone, 'customerTelephone', {
                isRequired: function () { return self.phoneRequiredB2b; }
            });
        },

        /** Fetch + cache regions for an ISO country code (Magento directory API). */
        _loadRegions: function (countryCode) {
            var self = this;
            if (!countryCode) { self.regionsList([]); return; }
            if (regionCache[countryCode] !== undefined) {
                self.regionsList(regionCache[countryCode]);
                return;
            }
            var restBase = (self.endpointUrls && self.endpointUrls.restBase) || '/rest/V1/';
            $.ajax({url: restBase + 'directory/countries/' + countryCode, method: 'GET', dataType: 'json'})
                .done(function (data) {
                    var regions = (data && data.available_regions) || [];
                    var list = regions.map(function (r) { return {value: r.code, label: r.name}; });
                    regionCache[countryCode] = list;
                    self.regionsList(list);
                })
                .fail(function () { regionCache[countryCode] = []; self.regionsList([]); });
        },

        /** Reusable observable for a dynamic field code (preserves entered value). */
        valueFor: function (code) {
            if (!this.fieldValues[code]) {
                this.fieldValues[code] = ko.observable('');
                // Same live-clear behavior as the static fields (see _clearOnInput)
                // — required dynamic Tax & Legal fields (Business Registration
                // Number, DUNS, PO Number, etc.) highlight red via
                // hasError('dyn_' + code); clear that highlight as soon as a
                // value is entered, instead of only on the next submit attempt.
                this._clearOnInput(this.fieldValues[code], 'dyn_' + code);
            }
            return this.fieldValues[code];
        },

        /**
         * Capture the File chosen for a Tax Exempt upload field. The actual file
         * is held until Save / Continue to Payment, when _uploadPendingFiles() POSTs
         * it. `valueObservable` keeps the filename so required-field validation
         * passes; a previously uploaded server path is cleared so the new file wins.
         */
        setTaxExemptFile: function (code, valueObservable, file) {
            if (!this._taxExemptFiles) { this._taxExemptFiles = {}; }
            if (!this._taxExemptPaths) { this._taxExemptPaths = {}; }
            this._taxExemptFiles[code] = file || null;
            delete this._taxExemptPaths[code];
            valueObservable(file ? file.name : '');
        },

        /**
         * Upload any pending Tax Exempt files to the server, recording each
         * returned relative path in _taxExemptPaths so _collectPayload() can send
         * it. Returns a jQuery promise that resolves once all uploads finish (or
         * immediately when there is nothing to upload).
         */
        _uploadPendingFiles: function () {
            var self = this;
            var files = this._taxExemptFiles || {};
            var pending = Object.keys(files).filter(function (code) { return files[code]; });
            if (!pending.length || !this.endpointUrls.upload) {
                return $.Deferred().resolve().promise();
            }
            return $.when.apply($, pending.map(function (code) {
                var fd = new FormData();
                fd.append('form_key', self._currentFormKey());
                fd.append('tax_exempt_file', files[code]);
                return $.ajax({
                    url: self.endpointUrls.upload, method: 'POST', data: fd,
                    processData: false, contentType: false, dataType: 'json'
                }).then(function (res) {
                    if (res && res.success && res.path) {
                        self._taxExemptPaths[code] = res.path;
                        self._taxExemptFiles[code] = null; // don't re-upload
                    } else {
                        return $.Deferred().reject(res && res.message).promise();
                    }
                });
            }));
        },

        /**
         * Rebuild the tax-status dropdown + the dynamic Tax & Legal fields for the
         * current country group and tax status.
         */
        renderFields: function () {
            var self = this;
            var group = this.countryGroup();
            // Per-group tax-status options (FR/SG all 3, UAE 2, US/EU/ROW reg only).
            var allowed = taxOptions[group] || ['reg'];

            this.taxStatusList(allowed.map(function (v) {
                return {value: v, label: taxLabels[v]};
            }));
            if (allowed.indexOf(this.taxStatus()) === -1) {
                this.taxStatus(allowed[0]);
                return; // subscription re-triggers renderFields
            }

            var defs = fieldDefs[group + '_' + this.taxStatus()] || [];
            this.dynamicFields(defs.map(function (def) {
                return {
                    code: def.code,
                    label: $t(def.label),
                    tooltip: def.tooltip ? $t(def.tooltip) : '',
                    type: def.type,
                    isFile: def.type === 'file' || def.type === 'file_req',
                    required: def.type === 'req' || def.type === 'file_req',
                    value: self.valueFor(def.code)
                };
            }));

            // France: auto-extract SIREN (first 9 digits) from SIRET, look up
            // the legal name from INSEE once the full 14-digit SIRET is entered,
            // and validate the checksum as soon as 14 digits are present.
            if (!this._sirenBound) {
                this.siret.subscribe(function (val) {
                    if (val && val.length >= 9) { self.siren(val.substring(0, 9)); }
                    if (val && val.length === 14) { self.lookupCompany(val); }

                    if (!val) {
                        self.siretStatus('idle');
                        self.siretMessage('');
                    } else if (val.length < 14) {
                        // Still typing — don't flag as invalid mid-entry.
                        self.siretStatus('idle');
                        self.siretMessage('');
                    } else if (isValidSiret(val)) {
                        self.siretStatus('valid');
                        self.siretMessage($t('Valid SIRET number.'));
                    } else {
                        self.siretStatus('invalid');
                        self.siretMessage($t('Invalid SIRET number.'));
                    }
                });
                this._sirenBound = true;
            }

            // VAT Intracommunity Number: live VIES check (see Controller\Vat\Validate),
            // debounced so it doesn't fire on every keystroke. Bound once; re-runs
            // whenever the field's value changes for any country group that uses it.
            if (!this._vatBound) {
                this.valueFor('vat_intracommunity').subscribe(function () {
                    self._scheduleVatValidation();
                });
                this._vatBound = true;
                // A prefilled VAT number (returning shopper / earlier save) needs
                // checking too, not just edits made after this point.
                if (this.valueFor('vat_intracommunity')()) { this._scheduleVatValidation(); }
            }
        },

        /** Debounced VIES check for the current VAT Intracommunity Number + country. */
        _scheduleVatValidation: function () {
            var self = this;
            var value = this.valueFor('vat_intracommunity')();

            clearTimeout(this._vatTimer);

            if (!value) {
                this.vatStatus('idle');
                this.vatMessage('');
                return;
            }
            if (!this.endpointUrls.validateVat) { return; }

            this.vatStatus('checking');
            this.vatMessage('');
            this._vatTimer = setTimeout(function () {
                var requestedValue = value;
                var requestedCountry = self.country();
                $.ajax({
                    url: self.endpointUrls.validateVat, method: 'POST', dataType: 'json',
                    data: {form_key: self._currentFormKey(), country_id: requestedCountry, vat_number: requestedValue}
                }).done(function (res) {
                    // Stale response (value/country changed while the request was
                    // in flight) — a fresher request already superseded this one.
                    if (requestedValue !== self.valueFor('vat_intracommunity')() || requestedCountry !== self.country()) {
                        return;
                    }
                    if (!res || !res.success || res.valid === null || res.valid === undefined) {
                        self.vatStatus('unknown');
                        self.vatMessage((res && res.message) || $t('Unable to verify the VAT number right now.'));
                    } else if (res.valid) {
                        self.vatStatus('valid');
                        self.vatMessage(res.message || $t('VAT number is valid.'));
                    } else {
                        self.vatStatus('invalid');
                        self.vatMessage(res.message || $t('VAT number is not valid.'));
                    }
                }).fail(function () {
                    if (requestedValue !== self.valueFor('vat_intracommunity')() || requestedCountry !== self.country()) {
                        return;
                    }
                    self.vatStatus('unknown');
                    self.vatMessage($t('Unable to verify the VAT number right now.'));
                });
            }, 600);
        },

        /**
         * INSEE (FR) company auto-fill: legal name, trade name, and postal
         * address from the SIRET the shopper just typed (see
         * Controller\Company\Lookup / Model\Insee\SireneClient). Each field
         * is only overwritten when INSEE actually returned a value, so a
         * field INSEE has nothing for (e.g. street2 is often blank) never
         * clobbers something the shopper already typed.
         */
        lookupCompany: function (registration) {
            var self = this;
            if (!this.endpointUrls.lookup) { return; }
            $.ajax({
                url: this.endpointUrls.lookup, method: 'GET', dataType: 'json',
                data: {registration: registration, country: this.country()}
            }).done(function (data) {
                if (!data) { return; }
                if (data.legalName) {
                    self.companyLegalName(data.legalName);
                    self.isCompanyNameAuto(true);
                }
                if (data.tradeName) { self.companyTradeName(data.tradeName); }
                if (data.street1) { self.companyStreet1(data.street1); }
                if (data.street2) { self.companyStreet2(data.street2); }
                if (data.city) { self.companyCity(data.city); }
                if (data.postcode) { self.companyZip(data.postcode); }
            });
        },

        // ---- Validation -----------------------------------------------------

        hasError: function (key) { return !!this.errors()[key]; },

        /** Removes one field's error/highlight; clears the top banner too once none remain. */
        clearError: function (key) {
            var errs = this.errors();
            if (!errs[key]) { return; }
            var next = {};
            Object.keys(errs).forEach(function (k) {
                if (k !== key) { next[k] = errs[k]; }
            });
            this.errors(next);
            if (Object.keys(next).length === 0) { this.globalError(''); }
        },

        /**
         * Adds one field's error/highlight. Deliberately does NOT touch the
         * top banner — that only appears after an actual submit attempt (see
         * validateBilling/proceedToPayment), not just because the shopper
         * cleared a field mid-edit.
         */
        setError: function (key) {
            var errs = this.errors();
            if (errs[key]) { return; }
            var next = {};
            Object.keys(errs).forEach(function (k) { next[k] = errs[k]; });
            next[key] = true;
            this.errors(next);
        },

        /**
         * Clears a field's error as soon as it holds an acceptable value —
         * so a red-highlighted required field (and the top banner, once no
         * errors remain) resets live as the shopper types, instead of only
         * on the next full validateBilling() run.
         */
        _clearOnInput: function (observable, key, isValid) {
            var self = this;
            observable.subscribe(function (val) {
                if (isValid ? isValid(val) : !!val) { self.clearError(key); }
            });
        },

        /**
         * Like _clearOnInput, but also (re-)shows the error the moment the
         * field is emptied back out while still required — not just when it
         * holds an acceptable value. Used only for Phone (see
         * _initSubscriptions) so clearing an already-filled Phone field
         * immediately turns it red again, matching what the next submit
         * attempt would flag, instead of waiting for that submit attempt.
         *
         * @param {ko.observable} observable
         * @param {string} key
         * @param {{isValid?: function, isRequired?: function}} [options]
         *   isValid defaults to a truthy check; isRequired defaults to
         *   always-required (pass one for fields whose requiredness depends
         *   on the current B2C/B2B profile, config toggle, or country).
         */
        _liveValidate: function (observable, key, options) {
            var self = this;
            options = options || {};
            var isValid = options.isValid || function (v) { return !!v; };
            var isRequired = options.isRequired || function () { return true; };
            observable.subscribe(function (val) {
                if (isValid(val)) {
                    self.clearError(key);
                } else if (isRequired()) {
                    self.setError(key);
                } else {
                    self.clearError(key);
                }
            });
        },

        validateBilling: function () {
            var self = this;
            var errs = {};

            if (!this.country()) { errs.country = true; }

            if (this.isB2C()) {
                if (!this.b2cFirstName()) { errs.b2cFirstName = true; }
                if (!this.b2cLastName()) { errs.b2cLastName = true; }
                if (this.b2cEmail() && !emailRe.test(this.b2cEmail())) { errs.b2cEmail = true; }
                if (this.showGenderFieldB2c && !this.gender()) { errs.gender = true; }
                if (this.phoneRequiredB2c && !this.b2cPhone()) { errs.b2cPhone = true; }
                if (this.showNationalityFieldB2c && !this.nationality()) { errs.nationality = true; }
                if (!this.street1()) { errs.street1 = true; }
                if (!this.city()) { errs.city = true; }
                if (this.showResidencyDeclaration() && !this.residencyDeclaration()) {
                    errs.residencyDeclaration = true;
                }
            } else {
                if (!this.invoiceFirstName()) { errs.invoiceFirstName = true; }
                if (!this.invoiceLastName()) { errs.invoiceLastName = true; }
                if (!this.invoiceEmail() || !emailRe.test(this.invoiceEmail())) { errs.invoiceEmail = true; }
                if (this.showGenderFieldB2b && !this.gender()) { errs.gender = true; }
                if (this.phoneRequiredB2b && !this.customerTelephone()) { errs.customerTelephone = true; }
                if (this.showNationalityFieldB2b && !this.nationality()) { errs.nationality = true; }
                if (!this.companyLegalName()) { errs.companyLegalName = true; }
                // companyTradeName (Commercial Company Name) is optional per spec
                if (!this.organizationType()) { errs.organizationType = true; }
                if (!this.jobIndustry()) { errs.jobIndustry = true; }
                if (!this.companyStreet1()) { errs.companyStreet1 = true; }
                if (!this.companyCity()) { errs.companyCity = true; }
                if (this.isFrance() && !isValidSiret(this.siret())) { errs.siret = true; }
                this.dynamicFields().forEach(function (f) {
                    if (f.required && !f.value()) { errs['dyn_' + f.code] = true; }
                    if (f.code === 'vat_intracommunity' && f.required && f.value()
                        && (self.vatStatus() === 'invalid' || self.vatStatus() === 'checking')) {
                        errs['dyn_' + f.code] = true;
                    }
                });
                if (this.showGstDeclaration() && !this.gstDeclaration()) { errs.gstDeclaration = true; }
            }

            this.errors(errs);
            return Object.keys(errs).length === 0;
        },

        // ---- Persistence ----------------------------------------------------

        /**
         * The form_key baked into the page at load time (this.formKeyValue)
         * can be stale by the time the shopper submits — most commonly on a
         * brand-new tab/session where the cookie finishes settling slightly
         * after the page's own HTML was generated. Magento keeps a `form_key`
         * cookie in sync with the CURRENT session on every request, so read
         * that fresh at submit time instead; it self-corrects without the
         * shopper needing to manually refresh. Falls back to the page-load
         * value only if the cookie genuinely isn't there yet.
         */
        _currentFormKey: function () {
            var cookieValue = $.mage && $.mage.cookies ? $.mage.cookies.get('form_key') : null;
            return cookieValue || this.formKeyValue;
        },

        _collectPayload: function () {
            var data = {
                form_key: this._currentFormKey(),
                financing_profile: this.financingProfile(),
                country_id: this.country() || 'FR',
                peoplesoft_id: this._peoplesoftId || ''
            };

            if (this.isB2C()) {
                data.firstname = this.b2cFirstName();
                data.lastname = this.b2cLastName();
                data.email = this.b2cEmail();
                data.telephone = this.b2cPhone();
                data.gender = this.gender();
                data.nationality = this.nationality();
                data.street1 = this.street1();
                data.street2 = this.street2();
                data.city = this.city();
                data.region = this.region();
                data.postcode = this.postcode();
                data.residency_declaration = this.showResidencyDeclaration() ? this.residencyDeclaration() : '';

                // Blank every B2B-only column so switching B2B -> B2C on the
                // same quote doesn't leave stale organisation/tax data (e.g.
                // a France SIRET auto-filled into uen/reg_type_*) behind.
                b2bOnlyColumns.forEach(function (code) { data[code] = ''; });
            } else {
                // Billing address contact = invoice recipient.
                data.firstname = this.invoiceFirstName();
                data.lastname = this.invoiceLastName();
                data.email = this.invoiceEmail();
                data.telephone = this.customerTelephone();
                data.gender = this.gender();
                data.nationality = this.nationality();
                data.street1 = this.companyStreet1();
                data.street2 = this.companyStreet2();
                data.city = this.companyCity();
                data.region = this.companyState();
                data.postcode = this.companyZip();

                data.invoice_recipient_firstname = this.invoiceFirstName();
                data.invoice_recipient_lastname = this.invoiceLastName();
                data.invoice_email = this.invoiceEmail();
                data.company_legal_name = this.companyLegalName();
                data.commercial_company_name = this.companyTradeName();
                data.organization_type = this.organizationType();
                data.job_industry = this.jobIndustry();
                data.tax_registration_status = this.taxStatus();
                data.gst_declaration = this.showGstDeclaration() ? this.gstDeclaration() : '';
                // B2C-only column — never applicable here.
                data.residency_declaration = '';

                // Blank every dynamic Tax & Legal column first, then fill in
                // only the ones currently shown below. Without this, a code
                // that isn't part of the CURRENT country/tax-status combo
                // (e.g. `certificate_id` after leaving "Exempt", or `uen`
                // after leaving France) is simply absent from the payload,
                // and Controller\Billing\Save only overwrites a column when
                // its key is present in the request — so the old value from
                // whatever combo was active last time silently survives.
                allDynamicFieldCodes.forEach(function (code) { data[code] = ''; });

                // France: SIRET persists in `uen` (EU tax & legal does not use uen).
                if (this.isFrance() && this.siret()) {
                    data.uen = this.siret();
                    data.reg_type_label = 'SIRET';
                    data.reg_type_value = this.siret();
                }

                // Dynamic Tax & Legal field values keyed by quote column code.
                // File fields send the uploaded server path (set by _uploadPendingFiles).
                var paths = this._taxExemptPaths || {};
                this.dynamicFields().forEach(function (f) {
                    data[f.code] = (f.isFile && paths[f.code]) ? paths[f.code] : f.value();
                });
            }

            return data;
        },

        /** Matches the exact message Controller\Billing\Save::createCsrfValidationException returns. */
        _isSessionExpiredResponse: function (res) {
            return !!(res && res.success === false && typeof res.message === 'string'
                && res.message.indexOf('session has expired') !== -1);
        },

        /**
         * POST the billing payload, with ONE silent automatic retry if the
         * server reports the CSRF "session has expired" failure. That
         * response is itself what causes Magento to (re)set a correct
         * form_key cookie for this session — so re-reading it via
         * _currentFormKey() and resubmitting once resolves the same class of
         * first-visit issue a manual page refresh does, without the shopper
         * having to do it themselves. allowRetry defaults to true; passed
         * false on the retry attempt itself so this can never loop.
         */
        _postBilling: function (allowRetry) {
            var self = this;
            var dfd = $.Deferred();
            $.ajax({
                url: self.endpointUrls.save, method: 'POST', dataType: 'json', data: self._collectPayload()
            }).done(function (res) {
                if (allowRetry !== false && self._isSessionExpiredResponse(res)) {
                    self._postBilling(false).done(dfd.resolve).fail(dfd.reject);
                } else {
                    dfd.resolve(res);
                }
            }).fail(dfd.reject);
            return dfd.promise();
        },

        /**
         * "Continue to Payment": validate, save, then redirect the browser to
         * the URL the server returns (genuine native Magento checkout — see
         * Controller\Billing\Save). Not an in-page step switch anymore.
         */
        proceedToPayment: function () {
            var self = this;
            this.globalError('');
            if (!this.validateBilling()) {
                var msg = $t('Please complete all required fields highlighted below before continuing to payment.');
                this.globalError(msg);
                alert({ title: $t('Missing Information'), content: msg });
                return;
            }
            this.isLoading(true);
            this._uploadPendingFiles().then(function () {
                return self._postBilling();
            }).done(function (res) {
                if (res && res.success && res.redirect) {
                    window.location.href = res.redirect;
                    return; // leave isLoading(true) — the page is navigating away
                }
                self.isLoading(false);
                self.globalError((res && res.message) || $t('Unable to save billing data.'));
            }).fail(function (err) {
                self.isLoading(false);
                self.globalError((typeof err === 'string' && err)
                    || $t('Unable to save billing data. Please try again.'));
            });
        },

        /** "Save" (without advancing): persist Billing Information silently. */
        saveBilling: function () {
            var self = this;
            this.globalError('');
            this._uploadPendingFiles().then(function () {
                return self._postBilling();
            }).done(function (res) {
                if (!res || !res.success) {
                    self.globalError((res && res.message) || $t('Unable to save billing data.'));
                }
            }).fail(function (err) {
                self.globalError((typeof err === 'string' && err)
                    || $t('Unable to upload the file. Please try again.'));
            });
        }
    });
});
