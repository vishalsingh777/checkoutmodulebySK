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

    // Countries excluded from the Self-funded residency declaration.
    var residencyExcluded = ['SG', 'FR', 'AE', 'US'];

    // Region cache keyed by ISO country code (avoids repeat directory calls).
    var regionCache = {};

    // Dynamic Tax & Legal Information matrix (spec: tax-fields-changelogic md).
    // Each entry maps to a quote column "code"; type: req | opt | file.
    var DUNS     = {code: 'duns_number',    label: 'D-U-N-S Number (optional)',       type: 'opt'};
    var PO       = {code: 'po_number',      label: 'PO Number',                       type: 'opt'};
    var ROUTING  = {code: 'routing_address',label: 'Routing Address',                 type: 'opt'};
    var TAXREG   = {code: 'tax_id_number',  label: 'Tax Registration Number',         type: 'opt'};
    var CERT_OPT = {code: 'certificate_id', label: 'Certificate ID',                  type: 'opt'};
    var CERT_REQ = {code: 'certificate_id', label: 'Certificate ID',                  type: 'req'};
    var UPLOAD     = {code: 'tax_exempt_file',label: 'Upload Tax Exempt File',        type: 'file'};
    var UPLOAD_REQ = {code: 'tax_exempt_file',label: 'Upload Tax Exempt File',        type: 'file_req'};
    var VATUAE   = {code: 'vat_uae',        label: 'UAE VAT Number',                  type: 'req'};
    var TRN      = {code: 'trn',             label: 'TRN',                             type: 'req'};
    var EIN      = {code: 'ein',             label: 'EIN',                             type: 'req'};
    var REG_LABEL= {code: 'reg_type_label', label: 'Registration Type',               type: 'opt'};
    var REG_VALUE= {code: 'reg_type_value', label: 'Registration Type Value',         type: 'opt'};
    var VAT_IC   = {code: 'vat_intracommunity', label: 'VAT Intracommunity Number',   type: 'req'};
    var UEN_SG   = {code: 'uen', label: 'Business Registration Number (UEN)',         type: 'req'};
    var UEN_GEN  = {code: 'uen', label: 'Business Registration Number',               type: 'req'};
    var GST_NUM  = {code: 'gst_number', label: 'GST Number',                          type: 'req'};

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
            this.summaryData = ko.observable(this.summary || {items: [], totals: {}});
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
            // Tax Status dropdown: shown for all B2B country groups. FR/SG/UAE offer
            // a real choice; US/EU/ROW show the single "Tax Registered" option.
            this.showTaxStatus = ko.computed(function () {
                return self.isB2B();
            });
            this.hasRegions = ko.computed(function () { return self.regionsList().length > 0; });
            // GST Declaration: B2B, programme in Singapore, for every country group
            // EXCEPT Singapore, France, UAE and US (i.e. shown for EU and ROW).
            this.showGstDeclaration = ko.computed(function () {
                var excludedGroups = ['SG', 'FR', 'UAE', 'US'];
                return self.isB2B() && self.programmeIsSingapore
                    && excludedGroups.indexOf(self.countryGroup()) === -1;
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
         * Capture the File chosen for a Tax Exempt upload field. The actual file
         * is held until Save / Proceed to payment, when _uploadPendingFiles() POSTs
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
                fd.append('form_key', self.formKeyValue);
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
                    type: def.type,
                    isFile: def.type === 'file' || def.type === 'file_req',
                    required: def.type === 'req' || def.type === 'file_req',
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
                if (!this.gender()) { errs.gender = true; }
                if (!this.nationality()) { errs.nationality = true; }
                if (!this.companyLegalName()) { errs.companyLegalName = true; }
                // companyTradeName (Commercial Company Name) is optional per spec
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

        /** Step 1 "Proceed to payment": validate, save, reveal the payment step. */
        proceedToPayment: function () {
            var self = this;
            this.globalError('');
            if (!this.validateBilling()) {
                this.globalError($t('Please complete all required fields highlighted below.'));
                return;
            }
            this.isLoading(true);
            this._uploadPendingFiles().then(function () {
                return $.ajax({
                    url: self.endpointUrls.save, method: 'POST', dataType: 'json', data: self._collectPayload()
                });
            }).done(function (res) {
                if (res && res.success) {
                    if (res.totals) {
                        var current = self.summaryData();
                        self.summaryData({items: current.items, totals: res.totals});
                    }
                    self.currentStep('payment');
                    self._initStripe();
                    window.scrollTo({top: 0, behavior: 'smooth'});
                } else {
                    self.globalError((res && res.message) || $t('Unable to save billing data.'));
                }
            }).fail(function (err) {
                self.globalError((typeof err === 'string' && err)
                    || $t('Unable to save billing data. Please try again.'));
            }).always(function () {
                self.isLoading(false);
            });
        },

        /** "Save" (without advancing): persist Step 1 silently. */
        saveBilling: function () {
            var self = this;
            this.globalError('');
            this._uploadPendingFiles().then(function () {
                return $.ajax({
                    url: self.endpointUrls.save, method: 'POST', dataType: 'json', data: self._collectPayload()
                });
            }).done(function (res) {
                if (!res || !res.success) {
                    self.globalError((res && res.message) || $t('Unable to save billing data.'));
                }
            }).fail(function (err) {
                self.globalError((typeof err === 'string' && err)
                    || $t('Unable to upload the file. Please try again.'));
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
