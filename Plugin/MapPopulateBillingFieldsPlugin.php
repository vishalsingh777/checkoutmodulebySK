<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Plugin;

use Ewave\InseadIntegration\Model\PopulateQuote;
use Ewave\InseadIntegration\Model\PopulateQuote\Request as PopulateQuoteRequest;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Maps the extra billing keys posted by the 3rd-party integration (financing_profile,
 * tax_registration_status, company_legal_name, siret, ...) onto the quote columns the
 * custom billing form prefills from. Ewave's populate flow ignores these keys.
 * Only runs when the POST carries `financing_profile`, so legacy posts are untouched.
 */
class MapPopulateBillingFieldsPlugin
{
    private const TAX_STATUS_MAP = [
        'tax_registered'     => 'reg',
        'reg'                => 'reg',
        'not_tax_registered' => 'notreg',
        'non_tax_registered' => 'notreg',
        'notreg'             => 'notreg',
        'tax_exempt'         => 'exempt',
        'exempt'             => 'exempt',
    ];

    private const GENDER_MAP = [
        'm' => 'male', 'male' => 'male',
        'f' => 'female', 'female' => 'female',
        'nonbinary' => 'nonbinary', 'non binary' => 'nonbinary', 'x' => 'nonbinary',
    ];

    /** 3rd-party organization type codes => labels in billing-form.js organizationTypes. */
    private const ORGANIZATION_TYPE_MAP = [
        'BUSN' => 'Business',
        'EMSY' => 'Embassy',
        'FBSN' => 'Family Business',
        'FNDN' => 'Foundation',
        'INTG' => 'Intergovernmental Organization',
        'PQOR' => 'Listed Company',
        'NONP' => 'Non-Profit',
        'PORG' => 'Private Organization',
        'PROF' => 'Professional Firm',
        'SCHL' => 'School',
        'SOPR' => 'Sole Proprietorship',
        'SOOR' => 'State Owned Organization',
    ];

    /** 3rd-party Sector of activity codes => labels in billing-form.js jobIndustries. */
    private const JOB_INDUSTRY_MAP = [
        47 => 'Accounting', 150 => 'Agriculture', 94 => 'Airlines/Aviation',
        120 => 'Alternative Dispute Resolution', 125 => 'Alternative Medicine', 127 => 'Animation',
        19 => 'Apparel and Fashion', 50 => 'Architecture & Planning', 111 => 'Arts and Crafts',
        53 => 'Automotive', 52 => 'Aviation and Aerospace', 41 => 'Banking', 12 => 'Biotechnology',
        36 => 'Broadcast Media', 49 => 'Building Materials', 138 => 'Business Supplies and Equipment',
        129 => 'Capital Markets', 54 => 'Chemicals', 90 => 'Civic & Social Organization',
        51 => 'Civil Engineering', 128 => 'Commercial Real Estate', 109 => 'Computer Games',
        3 => 'Computer Hardware', 5 => 'Computer Networking', 4 => 'Computer Software',
        118 => 'Computer and Network Security', 48 => 'Construction', 11 => 'Consulting',
        24 => 'Consumer Electronics', 25 => 'Consumer Goods', 91 => 'Consumer Services',
        18 => 'Cosmetics', 65 => 'Dairy', 1 => 'Defense & Space', 99 => 'Design',
        151 => 'E-Commerce', 132 => 'E-Learning', 69 => 'Education Management',
        112 => 'Electrical/Electronic Manufacturing', 28 => 'Entertainment',
        86 => 'Environmental Services', 110 => 'Events Services', 76 => 'Executive Office',
        152 => 'Executive Search', 122 => 'Facilities Services', 63 => 'Farming',
        43 => 'Financial Services', 38 => 'Fine Art', 66 => 'Fishery',
        23 => 'Food and Beverages Production', 101 => 'Fund-Raising', 26 => 'Furniture',
        29 => 'Gambling & Casinos', 145 => 'Glass, Ceramics & Concrete',
        75 => 'Government Administration', 148 => 'Government Relations', 140 => 'Graphic Design',
        124 => 'Health, Wellness and Fitness', 68 => 'Higher Education', 14 => 'Hospital & Health Care',
        31 => 'Hospitality', 137 => 'Human Resources', 134 => 'Import and Export',
        88 => 'Individual and Family Services', 147 => 'Industrial Automation',
        84 => 'Information Services', 96 => 'Information Technology and Services', 42 => 'Insurance',
        74 => 'International Affairs', 141 => 'International Trade and Development', 6 => 'Internet',
        45 => 'Investment Banking', 46 => 'Investment Management', 73 => 'Judiciary',
        77 => 'Law Enforcement', 9 => 'Law Practice', 10 => 'Legal Services', 72 => 'Legislative Office',
        30 => 'Leisure, Travel and Tourism', 85 => 'Libraries', 116 => 'Logistics and Supply Chain',
        143 => 'Luxury Goods and Jewelry', 55 => 'Machinery', 95 => 'Maritime', 97 => 'Market Research',
        80 => 'Marketing and Advertising', 135 => 'Mechanical or Industrial Engineering',
        126 => 'Media Production', 17 => 'Medical Devices', 13 => 'Medical Practice',
        139 => 'Mental Health Care', 71 => 'Military', 56 => 'Mining & Metals',
        35 => 'Motion Pictures and Film', 37 => 'Museums and Institutions', 115 => 'Music',
        114 => 'Nanotechnology', 81 => 'Newspapers', 100 => 'Non-Profit Organization Management',
        57 => 'Oil & Energy', 113 => 'Online Media', 123 => 'Outsourcing/Offshoring',
        87 => 'Package/Freight Delivery', 146 => 'Packaging and Containers',
        61 => 'Paper & Forest Products', 39 => 'Performing Arts', 15 => 'Pharmaceuticals',
        131 => 'Philanthropy', 136 => 'Photography', 117 => 'Plastics', 107 => 'Political Organization',
        67 => 'Primary/Secondary Education', 83 => 'Printing', 153 => 'Private Equity',
        105 => 'Professional Training & Coaching', 102 => 'Program Development', 79 => 'Public Policy',
        98 => 'Public Relations and Communications', 78 => 'Public Safety', 82 => 'Publishing',
        62 => 'Railroad Manufacture', 64 => 'Ranching', 44 => 'Real Estate',
        40 => 'Recreational Facilities and Services', 89 => 'Religious Institutions',
        144 => 'Renewables & Environment', 70 => 'Research', 32 => 'Restaurants', 27 => 'Retail',
        121 => 'Security and Investigations', 7 => 'Semiconductors', 58 => 'Shipbuilding',
        20 => 'Sporting Goods', 33 => 'Sports', 104 => 'Staffing and Recruiting', 22 => 'Supermarkets',
        8 => 'Telecommunications', 60 => 'Textiles', 130 => 'Think Tanks', 21 => 'Tobacco',
        108 => 'Translation and Localization', 92 => 'Transportation/Trucking/Railroad',
        59 => 'Utilities', 106 => 'Venture Capital', 16 => 'Veterinary', 93 => 'Warehousing',
        133 => 'Wholesale', 142 => 'Wine and Spirits', 119 => 'Wireless', 103 => 'Writing and Editing',
    ];

    private const JOB_INDUSTRY_OVERRIDES_PATH = 'insead_checkout/insead_customcheckout/job_industry_map';

    /** Posted keys copied to the quote column of the same name, B2B only. */
    private const B2B_PASSTHROUGH = [
        'company_legal_name', 'commercial_company_name', 'invoice_email',
        'invoice_recipient_firstname', 'invoice_recipient_lastname',
        'duns_number', 'routing_address', 'certificate_id',
        'gst_number', 'tax_id_number',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function afterPopulate(PopulateQuote $subject, $result, PopulateQuoteRequest $request)
    {
        $profile = strtolower(trim((string) $request->getData('financing_profile')));
        if (!in_array($profile, ['b2b', 'b2c'], true)) {
            return $result;
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return $result;
        }

        $isB2b = $profile === 'b2b';
        $quote->setData('financing_profile', $profile);
        $quote->setData('is_btob', $isB2b ? 1 : 0);

        // Reference number supplied by APOL / the integrated platform. Applies
        // to both profiles and is only ever set here — it is deliberately NOT
        // in Controller\Billing\Save::ALLOWED_COLUMNS, so the shopper's own
        // form post can never overwrite it. Copied to the order (etc/fieldset.xml)
        // so the invoice template can print it.
        $referenceNumber = trim((string) $request->getData('reference_number'));
        if ($referenceNumber !== '') {
            $quote->setData('reference_number', $referenceNumber);
        }

        $gender = self::GENDER_MAP[strtolower(trim((string) $request->getData('gender')))] ?? '';
        if ($gender !== '') {
            $quote->setData('gender', $gender);
        }
        $nationality = $this->toIso2(trim((string) $request->getData('nationality')));
        if ($nationality !== '') {
            $quote->setData('nationality', $nationality);
        }

        if ($isB2b) {
            $this->mapB2b($quote, $request);
        }

        $this->cartRepository->save($quote);
        return $result;
    }

    private function mapB2b($quote, PopulateQuoteRequest $request): void
    {
        foreach (self::B2B_PASSTHROUGH as $key) {
            $value = trim((string) $request->getData($key));
            if ($value !== '') {
                $quote->setData($key, $value);
            }
        }

        // Only statements 1-4 are valid; Model\Gst\CustomerGroupResolver treats anything else as not GST-liable.
        $gstDeclaration = trim((string) ($request->getData('gst_declaration') ?: $request->getData('insead_gst')));
        if (in_array($gstDeclaration, ['1', '2', '3', '4'], true)) {
            $quote->setData('gst_declaration', $gstDeclaration);
        }

        $orgType = self::ORGANIZATION_TYPE_MAP[strtoupper(trim((string) $request->getData('organization_type')))] ?? '';
        if ($orgType !== '') {
            $quote->setData('organization_type', $orgType);
        }

        $jobIndustry = $this->resolveJobIndustry(trim((string) $request->getData('job_industry')));
        if ($jobIndustry !== '') {
            $quote->setData('job_industry', $jobIndustry);
        }

        $sector = trim((string) $request->getData('sector_of_activity'));
        if ($sector !== '') {
            $quote->setData('sector_of_activity', $sector);
        }

        // company_vat_number is the fallback for partners that only send the billing-address VAT.
        $vat = trim((string) $request->getData('vat_intracommunity')) ?: trim((string) $request->getData('company_vat_number'));
        if ($vat !== '') {
            $quote->setData('vat_intracommunity', $vat);
        }

        // UAE's TRN is stored in vat_uae; the partner sends it as a VAT number.
        $trn = trim((string) $request->getData('vat_uae')) ?: trim((string) $request->getData('trn'));
        if ($trn === '' && strtoupper((string) $request->getData('country_id')) === 'AE') {
            $trn = $vat;
        }
        if ($trn !== '') {
            $quote->setData('vat_uae', $trn);
        }

        $status = self::TAX_STATUS_MAP[strtolower(trim((string) $request->getData('tax_registration_status')))] ?? '';
        if ($status !== '') {
            $quote->setData('tax_registration_status', $status);
        }

        // France stores the SIRET in `uen`, mirroring what the form's _collectPayload() saves.
        $siret = preg_replace('/\s+/', '', (string) $request->getData('siret')) ?? '';
        $uen = $siret !== '' ? $siret : (trim((string) $request->getData('uen')) ?: trim((string) $request->getData('ein')));
        if ($uen !== '') {
            $quote->setData('uen', $uen);
        }

        [$regLabel, $regValue] = $this->resolveRegType($request, $siret, $trn);
        if ($regValue !== '') {
            $quote->setData('reg_type_label', $regLabel);
            $quote->setData('reg_type_value', $regValue);
        }
    }

    /**
     * reg_type_value follows the posted reg_type_label when its number is available;
     * otherwise falls back to the first of siret, duns_number, uen.
     *
     * @return array{0:string,1:string} label, value
     */
    private function resolveRegType(PopulateQuoteRequest $request, string $siret, string $trn): array
    {
        $uen = trim((string) $request->getData('uen')) ?: trim((string) $request->getData('ein'));
        $bySource = [
            'SIRET' => $siret,
            'DUNS' => trim((string) $request->getData('duns_number')),
            'UEN' => $uen,
            'CRN' => $uen,
            'BRN' => $uen,
            'EIN' => $uen,
            'TRN' => $trn,
        ];

        $postedLabel = trim((string) $request->getData('reg_type_label'));
        $postedKey = strtoupper($postedLabel);
        if ($postedKey !== '' && ($bySource[$postedKey] ?? '') !== '') {
            return [$postedLabel, $bySource[$postedKey]];
        }

        foreach (['SIRET', 'DUNS', 'UEN'] as $type) {
            if ($bySource[$type] !== '') {
                return [$postedLabel !== '' ? $postedLabel : $type, $bySource[$type]];
            }
        }

        return ['', ''];
    }

    /** Accepts a numeric code, or a label (which keeps working if production codes differ from UAT). */
    private function resolveJobIndustry(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $map = $this->jobIndustryMap();
        if (ctype_digit($value)) {
            return $map[(int) $value] ?? '';
        }
        foreach ($map as $label) {
            if (strcasecmp($label, html_entity_decode($value)) === 0) {
                return $label;
            }
        }

        return '';
    }

    /** Built-in map with the admin JSON overrides applied on top. */
    private function jobIndustryMap(): array
    {
        $json = trim((string) $this->scopeConfig->getValue(self::JOB_INDUSTRY_OVERRIDES_PATH, ScopeInterface::SCOPE_STORE));
        $overrides = $json !== '' ? json_decode($json, true) : null;

        return is_array($overrides) ? array_replace(self::JOB_INDUSTRY_MAP, $overrides) : self::JOB_INDUSTRY_MAP;
    }

    private function toIso2(string $code): string
    {
        if (strlen($code) === 2) {
            return strtoupper($code);
        }
        if (strlen($code) !== 3) {
            return '';
        }
        $country = $this->countryCollectionFactory->create()
            ->addCountryCodeFilter(strtoupper($code), 'iso3')
            ->getFirstItem();

        return (string) $country->getCountryId();
    }
}
