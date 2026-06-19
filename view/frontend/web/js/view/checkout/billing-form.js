/**
 * INSEAD Custom Checkout — standalone two-step page (Billing Information →
 * Payment) that mirrors the INSEAD Salesforce flow.
 *
 * Step 1 collects the Self-funded (B2C) or Sponsored (B2B) billing details with
 * country-group × tax-status driven Tax & Legal fields and a GST Declaration.
 * Step 2 takes payment via the real Stripe Payment Element (reusing
 * StripeIntegration_Payments) or Bank Transfer, then places the order.
 */
define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/translate'
], function (Component, ko, $, $t) {
    'use strict';

    // Stripe modules are loaded lazily (only when the payment step is reached)
    // so a slow/blocked Stripe.js CDN never prevents the billing form from
    // rendering. Resolved into these vars by _initStripe().
    var getRequiresAction = null;

    // EU member-state ISO codes (France included) — the EU billing group.
    var euCountries = [
        'FR','AT','BE','BG','HR','CY','CZ','DK','EE','FI','DE',
        'GR','HU','IE','IT','LV','LT','LU','MT','NL','PL',
        'PT','RO','SK','SI','ES','SE'
    ];

    // Map any ISO 2-letter country code to a billing group (SG | EU | ROW).
    function countryToGroup(iso) {
        if (iso === 'SG') { return 'SG'; }
        if (euCountries.indexOf(iso) !== -1) { return 'EU'; }
        return 'ROW';
    }

    var taxLabels = {
        reg: $t('Tax Registered'),
        notreg: $t('Not Tax Registered'),
        exempt: $t('Tax Exempt')
    };

    // Gender options for the Self-funded customer-information section.
    var genderOptions = [
        {value: 'male', label: $t('Male')},
        {value: 'female', label: $t('Female')},
        {value: 'nonbinary', label: $t('Non binary')},
        {value: 'prefer_not', label: $t('Prefer not to answer')}
    ];

    // Countries excluded from the Self-funded residency declaration.
    var residencyExcluded = ['SG', 'FR', 'AE', 'US'];

    // Region cache keyed by ISO country code (avoids repeat directory calls).
    var regionCache = {};

    // Dynamic Tax & Legal Information matrix (spec: tax-fields-changelogic md).
    // Each entry maps to a quote column "code"; type: req | opt | file.
    var DUNS = {code: 'duns_number', label: 'D-U-N-S Number (optional)', type: 'opt'};
    var PO = {code: 'po_number', label: 'PO Number', type: 'opt'};
    var ROUTING = {code: 'routing_address', label: 'Routing Address', type: 'opt'};
    var TAXREG = {code: 'tax_id_number', label: 'Tax Registration Number', type: 'opt'};
    var CERT = {code: 'certificate_id', label: 'Certificate ID', type: 'req'};
    var UPLOAD = {code: 'tax_exempt_file', label: 'Upload Tax Exempt File', type: 'file'};

    var fieldDefs = {
        SG_reg: [
            {code: 'uen', label: 'Business Registration Number (UEN)', type: 'req'},
            DUNS,
            {code: 'gst_number', label: 'GST Number', type: 'req'},
            PO
        ],
        SG_notreg: [
            {code: 'uen', label: 'Business Registration Number (UEN)', type: 'req'},
            DUNS, PO
        ],
        SG_exempt: [CERT, DUNS, PO, UPLOAD],

        EU_reg: [
            DUNS,
            {code: 'vat_intracommunity', label: 'VAT Intracommunity Number', type: 'req'},
            PO, ROUTING
        ],
        EU_notreg: [DUNS, PO, ROUTING],
        EU_exempt: [CERT, DUNS, PO, ROUTING, UPLOAD],

        ROW_reg: [
            {code: 'uen', label: 'Business Registration Number', type: 'req'},
            DUNS, TAXREG, PO
        ],
        ROW_notreg: [
            {code: 'uen', label: 'Business Registration Number', type: 'req'},
            DUNS, TAXREG, PO
        ],
        ROW_exempt: [CERT, DUNS, TAXREG, PO, UPLOAD]
    };

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
            paymentMethods: {},
            stripe: {},
            summary: {},
            prefill: {},
            programmeInSingapore: true,
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

            this.endpointUrls = this.urls || {};
            this.formKeyValue = this.formKey || '';
            this.countriesList = ko.observableArray(this.countries || []);
            this.stripeConfig = this.stripe || {};
            this.paymentConfig = this.paymentMethods || {};
            this.summaryData = this.summary || {items: [], totals: {}};
            this.programmeIsSingapore = !!this.programmeInSingapore;

            // Billing-address identity (name/email/phone). Supplied by the upstream
            // flow and not shown for Self-funded; used server-side for the address.
            this.customerFirstName = pf.firstname || customer.firstname || '';
            this.customerLastName = pf.lastname || customer.lastname || '';
            this.customerEmail = pf.email || customer.email || '';
            this.customerTelephone = pf.telephone || '';

            // ---- Step 1: selector box ----
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
            this.residencyDeclaration = ko.observable(''); // 'agree' | 'disagree'

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
                tax_id_number: pf.tax_id_number, certificate_id: pf.certificate_id,
                duns_number: pf.duns_number, routing_address: pf.routing_address,
                po_number: pf.po_number
            };
            if (!isFR) { seed.uen = pf.uen; }
            for (var code in seed) {
                if (seed.hasOwnProperty(code)) { this.fieldValues[code] = ko.observable(seed[code] || ''); }
            }

            // GST Declaration.
            this.gstDeclaration = ko.observable('');

            // ---- Step 2: payment ----
            this.currentStep = ko.observable('billing'); // 'billing' | 'payment'
            this.acceptedTerms = ko.observable(false);
            this.paymentChoice = ko.observable(''); // 'card' | 'bank'

            // UI state.
            this.errors = ko.observable({});
            this.globalError = ko.observable('');
            this.isLoading = ko.observable(false);

            // Stripe runtime state.
            this._stripeReady = false;
            this._cardElements = null;
            this._cardMounted = false;
            this._bankElements = null;
            this._bankMounted = false;
        },

        _initComputed: function () {
            var self = this;
            this.isB2B = ko.computed(function () { return self.financingProfile() === 'b2b'; });
            this.isB2C = ko.computed(function () { return self.financingProfile() === 'b2c'; });
            this.countryGroup = ko.computed(function () { return countryToGroup(self.country()); });
            this.isFrance = ko.computed(function () { return self.country() === 'FR'; });
            this.hasRegions = ko.computed(function () { return self.regionsList().length > 0; });
            // GST Declaration: programme in Singapore AND company is Rest-of-World.
            this.showGstDeclaration = ko.computed(function () {
                return self.isB2B() && self.programmeIsSingapore && self.countryGroup() === 'ROW';
            });
            // Self-funded residency Declaration: Singapore legal entity AND B2C AND
            // billing country NOT in SG/FR/UAE/US.
            this.showResidencyDeclaration = ko.computed(function () {
                return self.isB2C() && self.programmeIsSingapore
                    && residencyExcluded.indexOf(self.country()) === -1;
            });
            this.cardMethod = this.paymentConfig.card || null;
            this.bankMethod = this.paymentConfig.bank || null;
            // Pay Now enabled only once the T&C is accepted and a method chosen.
            this.canPay = ko.computed(function () {
                return self.acceptedTerms() && !!self.paymentChoice() && !self.isLoading();
            });
        },

        _initSubscriptions: function () {
            var self = this;
            this.country.subscribe(function (val) {
                self._loadRegions(val);
                self.renderFields();
            });
            this.taxStatus.subscribe(function () { self.renderFields(); });
            this.financingProfile.subscribe(function () { self.globalError(''); });
            // Mount the relevant Stripe element when the payment choice changes.
            this.paymentChoice.subscribe(function (choice) {
                if (choice === 'card') { self._mountCard(); }
                if (choice === 'bank') { self._mountBank(); }
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
            $.ajax({url: '/rest/V1/directory/countries/' + countryCode, method: 'GET', dataType: 'json'})
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
            }
            return this.fieldValues[code];
        },

        /**
         * Rebuild the tax-status dropdown + the dynamic Tax & Legal fields for the
         * current country group and tax status.
         */
        renderFields: function () {
            var self = this;
            var group = this.countryGroup();
            // Singapore + EU expose all three statuses; Rest-of-World only reg/notreg/exempt too.
            var allowed = ['reg', 'notreg', 'exempt'];

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
                    type: def.type,
                    isFile: def.type === 'file',
                    required: def.type === 'req',
                    value: self.valueFor(def.code)
                };
            }));

            // France: auto-extract SIREN (first 9 digits) from SIRET, and look up
            // the legal name from INSEE once the full 14-digit SIRET is entered.
            if (!this._sirenBound) {
                this.siret.subscribe(function (val) {
                    if (val && val.length >= 9) { self.siren(val.substring(0, 9)); }
                    if (val && val.length === 14) { self.lookupCompany(val); }
                });
                this._sirenBound = true;
            }
        },

        /** INSEE (FR) / VIES (EU) company name auto-fill. */
        lookupCompany: function (registration) {
            var self = this;
            if (!this.endpointUrls.lookup) { return; }
            $.ajax({
                url: this.endpointUrls.lookup, method: 'GET', dataType: 'json',
                data: {registration: registration, country: this.country()}
            }).done(function (data) {
                if (data && data.legalName) {
                    self.companyLegalName(data.legalName);
                    self.isCompanyNameAuto(true);
                }
            });
        },

        // ---- Validation -----------------------------------------------------

        hasError: function (key) { return !!this.errors()[key]; },

        validateBilling: function () {
            var errs = {};
            var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!this.country()) { errs.country = true; }

            if (this.isB2C()) {
                if (!this.b2cFirstName()) { errs.b2cFirstName = true; }
                if (!this.b2cLastName()) { errs.b2cLastName = true; }
                if (this.b2cEmail() && !emailRe.test(this.b2cEmail())) { errs.b2cEmail = true; }
                if (!this.gender()) { errs.gender = true; }
                if (!this.b2cPhone()) { errs.b2cPhone = true; }
                if (!this.nationality()) { errs.nationality = true; }
                if (!this.street1()) { errs.street1 = true; }
                if (!this.city()) { errs.city = true; }
                if (this.showResidencyDeclaration() && !this.residencyDeclaration()) {
                    errs.residencyDeclaration = true;
                }
            } else {
                if (!this.invoiceFirstName()) { errs.invoiceFirstName = true; }
                if (!this.invoiceLastName()) { errs.invoiceLastName = true; }
                if (!this.invoiceEmail() || !emailRe.test(this.invoiceEmail())) { errs.invoiceEmail = true; }
                if (!this.companyLegalName()) { errs.companyLegalName = true; }
                if (!this.companyTradeName()) { errs.companyTradeName = true; }
                if (!this.organizationType()) { errs.organizationType = true; }
                if (!this.jobIndustry()) { errs.jobIndustry = true; }
                if (!this.companyStreet1()) { errs.companyStreet1 = true; }
                if (!this.companyCity()) { errs.companyCity = true; }
                if (this.isFrance() && !this.siret()) { errs.siret = true; }
                this.dynamicFields().forEach(function (f) {
                    if (f.required && !f.value()) { errs['dyn_' + f.code] = true; }
                });
                if (this.showGstDeclaration() && !this.gstDeclaration()) { errs.gstDeclaration = true; }
            }

            this.errors(errs);
            return Object.keys(errs).length === 0;
        },

        // ---- Persistence ----------------------------------------------------

        _collectPayload: function () {
            var data = {
                form_key: this.formKeyValue,
                financing_profile: this.financingProfile(),
                country_id: this.country() || 'FR'
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
            } else {
                // Billing address contact = invoice recipient.
                data.firstname = this.invoiceFirstName();
                data.lastname = this.invoiceLastName();
                data.email = this.invoiceEmail();
                data.telephone = this.customerTelephone;
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

                // France: SIRET persists in `uen` (EU tax & legal does not use uen).
                if (this.isFrance() && this.siret()) {
                    data.uen = this.siret();
                    data.reg_type_label = 'SIRET';
                    data.reg_type_value = this.siret();
                }

                // Dynamic Tax & Legal field values keyed by quote column code.
                this.dynamicFields().forEach(function (f) {
                    data[f.code] = f.value();
                });
            }

            return data;
        },

        /** Step 1 "Proceed to payment": validate, save, reveal the payment step. */
        proceedToPayment: function () {
            var self = this;
            this.globalError('');
            if (!this.validateBilling()) {
                this.globalError($t('Please complete all required fields highlighted below.'));
                return;
            }
            this.isLoading(true);
            $.ajax({
                url: this.endpointUrls.save, method: 'POST', dataType: 'json', data: this._collectPayload()
            }).done(function (res) {
                if (res && res.success) {
                    self.currentStep('payment');
                    self._initStripe();
                    window.scrollTo({top: 0, behavior: 'smooth'});
                } else {
                    self.globalError((res && res.message) || $t('Unable to save billing data.'));
                }
            }).fail(function () {
                self.globalError($t('Unable to save billing data. Please try again.'));
            }).always(function () {
                self.isLoading(false);
            });
        },

        /** "Save" (without advancing): persist Step 1 silently. */
        saveBilling: function () {
            var self = this;
            this.globalError('');
            $.ajax({
                url: this.endpointUrls.save, method: 'POST', dataType: 'json', data: this._collectPayload()
            }).done(function (res) {
                if (!res || !res.success) {
                    self.globalError((res && res.message) || $t('Unable to save billing data.'));
                }
            });
        },

        backToBilling: function () {
            this.currentStep('billing');
            this.globalError('');
        },

        // ---- Stripe payment -------------------------------------------------

        _initStripe: function () {
            var self = this;
            if (this._stripeReady || !this.stripeConfig || !this.stripeConfig.initParams) { return; }
            this._stripeReady = true;
            // Lazily pull in Stripe.js + the requires-action action.
            require([
                'StripeIntegration_Payments/js/stripe',
                'StripeIntegration_Payments/js/action/get-requires-action'
            ], function (stripeModule, requiresAction) {
                getRequiresAction = requiresAction;
                window.stripe.initStripe(self.stripeConfig.initParams);
                // Default to card when available, then mount the chosen element.
                if (self.cardMethod && !self.paymentChoice()) {
                    self.paymentChoice('card');
                } else if (self.bankMethod && !self.paymentChoice()) {
                    self.paymentChoice('bank');
                } else if (self.paymentChoice() === 'card') {
                    self._mountCard();
                } else if (self.paymentChoice() === 'bank') {
                    self._mountBank();
                }
            }, function () {
                self.globalError($t('Unable to load the secure payment library. Please refresh and try again.'));
            });
        },

        _mountCard: function () {
            if (this._cardMounted || !window.stripe || !window.stripe.stripeJs) { return; }
            try {
                this._cardElements = window.stripe.stripeJs.elements(this.stripeConfig.elementOptions);
                this._cardElements.create('payment').mount('#insead-stripe-card');
                this._cardMounted = true;
            } catch (e) {
                this.globalError($t('Unable to load the card payment form.'));
                window.console && console.error(e);
            }
        },

        _mountBank: function () {
            if (this._bankMounted || !window.stripe || !window.stripe.stripeJs
                || !this.stripeConfig.bankElementOptions) { return; }
            try {
                this._bankElements = window.stripe.stripeJs.elements(this.stripeConfig.bankElementOptions);
                this._bankElements.create('payment').mount('#insead-stripe-bank');
                this._bankMounted = true;
            } catch (e) {
                window.console && console.error(e);
            }
        },

        _billingDetails: function () {
            var name = this.isB2B()
                ? (this.invoiceFirstName() + ' ' + this.invoiceLastName())
                : (this.b2cFirstName() + ' ' + this.b2cLastName());
            return {
                name: name.trim(),
                email: this.isB2B() ? this.invoiceEmail() : this.b2cEmail(),
                address: {
                    line1: this.isB2B() ? this.companyStreet1() : this.street1(),
                    line2: this.isB2B() ? this.companyStreet2() : this.street2(),
                    city: this.isB2B() ? this.companyCity() : this.city(),
                    state: this.isB2B() ? this.companyState() : this.region(),
                    postal_code: this.isB2B() ? this.companyZip() : this.postcode(),
                    country: this.country()
                }
            };
        },

        /** "Pay Now": create the Stripe payment method, place the order, run 3DS. */
        placeOrder: function () {
            var self = this;
            this.globalError('');
            if (!this.acceptedTerms()) {
                this.globalError($t('Please accept the Terms and Conditions.'));
                return;
            }
            var choice = this.paymentChoice();
            if (!choice) {
                this.globalError($t('Please select a payment method.'));
                return;
            }

            var elements = choice === 'card' ? this._cardElements : this._bankElements;
            var methodCode = choice === 'card'
                ? (this.cardMethod && this.cardMethod.code)
                : (this.bankMethod && this.bankMethod.code);

            if (!elements || !methodCode) {
                this.globalError($t('The payment form is not ready. Please try again.'));
                return;
            }

            this.isLoading(true);
            elements.submit().then(function () {
                window.stripe.stripeJs.createPaymentMethod({
                    elements: elements,
                    params: {billing_details: self._billingDetails()}
                }).then(function (result) {
                    if (result.error) {
                        self.isLoading(false);
                        return self.globalError(result.error.message);
                    }
                    self._placeOrderOnServer(methodCode, result.paymentMethod.id);
                });
            }, function (result) {
                self.isLoading(false);
                self.globalError((result && result.error && result.error.message)
                    || $t('A payment submission error has occurred.'));
            });
        },

        _placeOrderOnServer: function (methodCode, paymentMethodId) {
            var self = this;
            $.ajax({
                url: this.endpointUrls.place, method: 'POST', dataType: 'json',
                data: {
                    form_key: this.formKeyValue,
                    payment_method: methodCode,
                    payment_method_id: paymentMethodId
                }
            }).done(function (res) {
                if (res && res.success) {
                    self._handleRequiresAction();
                } else {
                    self.isLoading(false);
                    self.globalError((res && res.message) || $t('Unable to place the order.'));
                }
            }).fail(function () {
                self.isLoading(false);
                self.globalError($t('Unable to place the order. Please try again.'));
            });
        },

        /** After placement, run any Stripe next-action (3DS / bank instructions). */
        _handleRequiresAction: function () {
            var self = this;
            getRequiresAction(function (clientSecret) {
                if (clientSecret && clientSecret.length) {
                    window.stripe.authenticateCustomer(clientSecret, function (err) {
                        if (err) {
                            self.isLoading(false);
                            return self.globalError(err);
                        }
                        self._redirectSuccess();
                    });
                } else {
                    self._redirectSuccess();
                }
            });
        },

        _redirectSuccess: function () {
            window.location.href = this.endpointUrls.success;
        }
    });
});
