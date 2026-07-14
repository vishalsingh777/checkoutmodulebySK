<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ewave\CustomerVat\Helper\AddressAttributeConfig;
use Insead\CustomCheckout\Model\OrganizationLookup;
use Insead\CustomCheckout\Model\CompanyProfile;

/**
 * Bootstrap configuration for the INSEAD Billing Information step —
 * ONLY the billing form itself (selector box, B2C/B2B fields, Tax & Legal
 * cascade, GST Declaration). No order summary, discount code, declarations,
 * or Proforma button live on this page; those either have a native
 * equivalent on the next page (order summary, discount code — native
 * checkout's own sidebar already provides both) or are added onto that next
 * page explicitly via Block\PaymentPageExtras (Declarations, Proforma),
 * without touching checkout.root.
 *
 * This is now the ONLY step this module renders. Payment is handled by
 * genuine, unmodified native Magento checkout: Controller\Billing\Save marks
 * the quote as `insead_billing_confirmed` and redirects the browser back to
 * /checkout, at which point Observer\AddCustomCheckoutLayoutHandle stops
 * adding the takeover handle and checkout_index_index.xml renders normally
 * (skipping straight to the payment step, since the quote is virtual). See
 * that observer's docblock for the full rationale — in short, this means
 * every enabled payment method (Stripe, Braintree, PayPal, Sogecommerce,
 * Check/Money Order, Cash On Delivery, anything enabled later) works exactly
 * as it does in stock Magento, with zero method-specific code in this module.
 */
class Checkout extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly FormKey $formKey,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrganizationLookup $organizationLookup,
        private readonly CompanyProfile $companyProfile,
        private readonly AddressAttributeConfig $vatConfig,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The full uiComponent bootstrap node (component path + config spread at
     * the top level), JSON encoded. Keys are merged onto the view-model by
     * Magento_Ui/js/core/app, so they read as this.<key>.
     */
    public function getJsonConfig(): string
    {
        return $this->json->serialize([
            'component'      => 'Insead_CustomCheckout/js/view/checkout/billing-form',
            'isLoggedIn'     => $this->customerSession->isLoggedIn(),
            'customer'       => $this->getCustomerData(),
            'countries'      => $this->getCountries(),
            'prefill'        => $this->getPrefill(),
            'programmeInSingapore' => $this->isProgrammeInSingapore(),
            'formKey'             => $this->formKey->getFormKey(),
            'vatValidationEnabled' => $this->isVatValidationEnabled(),
            'urls'                => [
                'lookup'      => $this->getUrl('insead_checkout/company/lookup'),
                'save'        => $this->getUrl('insead_checkout/billing/save'),
                'upload'      => $this->getUrl('insead_checkout/billing/upload'),
                'cart'        => $this->getUrl('checkout/cart'),
                'validateVat' => $this->getUrl('insead_checkout/vat/validate'),
            ],
        ]);
    }


    /**
     * Whether the live VAT Intracommunity Number check should run. Reuses
     * Ewave_CustomerVat's own "Enable Automatic Assignment to Customer Group"
     * toggle (Stores > Configuration > Ewave > Customer VAT > Assign by
     * specific address attributes), so this store's existing VAT-processing
     * setting is respected instead of always validating regardless of it.
     */
    private function isVatValidationEnabled(): bool
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return $this->vatConfig->isAutoGroupAssignEnabled($storeId);
    }

    /**
     * @return array{firstname:string,lastname:string,email:string}
     */
    private function getCustomerData(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return ['firstname' => '', 'lastname' => '', 'email' => ''];
        }
        $customer = $this->customerSession->getCustomer();
        return [
            'firstname' => (string) $customer->getFirstname(),
            'lastname'  => (string) $customer->getLastname(),
            'email'     => (string) $customer->getEmail(),
        ];
    }

    /**
     * Full ISO country list for the Country / Nationality dropdowns.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function getCountries(): array
    {
        $out = [];
        foreach ($this->countryCollectionFactory->create()->loadByStore() as $country) {
            $name = $country->getName();
            if ($name) {
                $out[] = ['value' => (string) $country->getId(), 'label' => (string) $name];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Values to prefill the checkout form with — sourced from the quote that the
     * Ewave_InseadIntegration "populate" flow already filled in (billing address
     * + custom columns). The form is read-only-by-default for identity fields
     * (name/email/phone) that the upstream flow supplies.
     *
     * @return array<string,mixed>
     */
    private function getPrefill(): array
    {
        $data = [];
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                return $data;
            }
            // Read billing address directly from DB — the ORM's in-memory billing
            // address object can hold stale data (set before Save.php persisted the
            // B2B fields) on the same request, causing wrong country/name prefill.
            $conn = $this->resourceConnection->getConnection();
            $row = $conn->fetchRow(
                'SELECT * FROM quote_address WHERE quote_id = ? AND address_type = "billing" LIMIT 1',
                [$quote->getId()]
            ) ?: [];

            $streetRaw = $row['street'] ?? '';
            $streetParts = array_values(array_filter(explode("\n", (string) $streetRaw)));

            $val = static fn ($v) => $v === null ? '' : (string) $v;

            // B2B/B2C: prefer the choice actually saved by Controller\Billing\Save
            // (the `financing_profile` column) over Ewave's `is_btob`, which only
            // reflects the ORIGINAL populate flow and is never updated by this
            // module. Without this, returning via "Edit Billing Information"
            // after switching profiles (or after any save at all) would show
            // the wrong B2C/B2B panel — looking like entered data had vanished,
            // when really the right-hand fields just weren't the ones visible.
            // Before this quote has ever been through the custom step,
            // financing_profile is empty, so is_btob is the only signal available.
            $financingProfile = (string) $quote->getData('financing_profile');
            $isBtob = $financingProfile !== ''
                ? ($financingProfile === 'b2b' ? 1 : 0)
                : (int) $quote->getData('is_btob');

            $data = [
                // Billing address read fresh from DB.
                'firstname'  => $val($row['firstname'] ?? ''),
                'lastname'   => $val($row['lastname'] ?? ''),
                'email'      => $val($quote->getCustomerEmail() ?: ($row['email'] ?? '')),
                'telephone'  => $val($row['telephone'] ?? ''),
                'street1'    => $val($streetParts[0] ?? ''),
                'street2'    => $val($streetParts[1] ?? ''),
                'city'       => $val($row['city'] ?? ''),
                'region'     => $val($row['region_code'] ?? $row['region'] ?? ''),
                'postcode'   => $val($row['postcode'] ?? ''),
                'country_id' => $val($row['country_id'] ?? $quote->getData('quote_country') ?? 'FR'),
                'company'    => $val($row['company'] ?? ''),
                'vat_id'     => $val($row['vat_id'] ?? ''),
                'gender'     => $val($quote->getData('gender')),
                'nationality' => $val($quote->getData('nationality')),
                'is_btob'    => $isBtob,
                // Custom INSEAD columns (may already be filled by a prior save).
                'company_legal_name'          => $val($quote->getData('company_legal_name')),
                'commercial_company_name'     => $val($quote->getData('commercial_company_name')),
                'organization_type'           => $val($quote->getData('organization_type')),
                'job_industry'                => $val($quote->getData('job_industry')),
                'tax_registration_status'     => $val($quote->getData('tax_registration_status')),
                'invoice_recipient_firstname' => $val($quote->getData('invoice_recipient_firstname')),
                'invoice_recipient_lastname'  => $val($quote->getData('invoice_recipient_lastname')),
                'invoice_email'               => $val($quote->getData('invoice_email')),
                'po_number'                   => $val($quote->getData('po_number')),
                'uen'                => $val($quote->getData('uen')),
                'vat_intracommunity' => $val($quote->getData('vat_intracommunity')),
                'gst_number'         => $val($quote->getData('gst_number')),
                'tax_id_number'      => $val($quote->getData('tax_id_number')),
                'certificate_id'     => $val($quote->getData('certificate_id')),
                'duns_number'        => $val($quote->getData('duns_number')),
                'routing_address'       => $val($quote->getData('routing_address')),
                'tax_exempt_file'       => $val($quote->getData('tax_exempt_file')),
                'peoplesoft_id'         => $val($quote->getData('peoplesoft_id')),
                'gst_declaration'       => $val($quote->getData('gst_declaration')),
                'residency_declaration' => $val($quote->getData('residency_declaration')),
            ];


            // A prior checkout may have saved the dummy placeholder '0000000000'
            // into quote_address. Treat it as empty so the B2B/B2C/order-history
            // prefill steps below can fill in the customer's real phone number.
            if (($data['telephone'] ?? '') === '0000000000') {
                $data['telephone'] = '';
            }

            // Returning customer: backfill any still-empty organization/tax
            // fields from their existing insead_mg_organization row (keyed by
            // customer_id). Guests are never written there, so nothing to do.
            if ($this->customerSession->isLoggedIn()) {
                $customerId = (int) $this->customerSession->getCustomerId();

                // peoplesoft_id is stored on customer_entity directly; read it
                // from the already-loaded customer model if the quote didn't have it.
                if (($data['peoplesoft_id'] ?? '') === '') {
                    $data['peoplesoft_id'] = $val(
                        $this->customerSession->getCustomer()->getData('peoplesoft_id')
                    );
                }

                foreach ($this->organizationLookup->findByCustomerId($customerId) as $field => $value) {
                    if (($data[$field] ?? '') === '') {
                        $data[$field] = $val($value);
                    }
                }

                // B2B: backfill the company's saved default billing address (link →
                // insead_company_address). Only for B2B so a B2C quote's personal
                // address is never overwritten with company data.
                if ($isBtob === 1) {
                    //print_r($data);die('Hey');
                    $companyAddress = $this->companyProfile->findDefaultAddressByCustomer($customerId);
                    $addressMap = [
                        'firstname'  => 'invoice_recipient_firstname',
                        'lastname'   => 'invoice_recipient_lastname',
                        'email'      => 'invoice_email',
                        'company'    => 'company_legal_name',
                        'street1'    => 'street1',
                        'street2'    => 'street2',
                        'city'       => 'city',
                        'region'     => 'region',
                        'postcode'   => 'postcode',
                        'country_id' => 'country_id',
                        'telephone'  => 'telephone',
                        'vat_id'     => 'vat_id',
                    ];
                    foreach ($addressMap as $addrField => $prefillKey) {
                        if (isset($companyAddress[$addrField]) && ($data[$prefillKey] ?? '') === '') {
                            $data[$prefillKey] = $val($companyAddress[$addrField]);
                        }
                    }
                    // Company address may have no phone; fall back to the buyer's
                    // personal Magento default billing address phone so the field
                    // is not blank for B2B customers who have a phone on their account.
                    if (($data['telephone'] ?? '') === '') {
                        $defaultBilling = $this->customerSession->getCustomer()->getDefaultBillingAddress();
                        if ($defaultBilling && $defaultBilling->getId()) {
                            $personalPhone = (string) $defaultBilling->getTelephone();
                            if ($personalPhone !== '' && $personalPhone !== '0000000000') {
                                $data['telephone'] = $personalPhone;
                            }
                        }
                    }
                }

                // B2C: backfill the buyer's personal billing address from their
                // saved Magento customer address (default billing). Only for non-B2B
                // so a company quote is never overwritten with personal data.
                if ($isBtob !== 1) {
                    $defaultBilling = $this->customerSession->getCustomer()->getDefaultBillingAddress();
                    if ($defaultBilling && $defaultBilling->getId()) {
                        $cStreet = $defaultBilling->getStreet();
                        $cStreet = is_array($cStreet) ? $cStreet : [];
                        $personalMap = [
                            'firstname'  => $defaultBilling->getFirstname(),
                            'lastname'   => $defaultBilling->getLastname(),
                            'street1'    => $cStreet[0] ?? '',
                            'street2'    => $cStreet[1] ?? '',
                            'city'       => $defaultBilling->getCity(),
                            'region'     => $defaultBilling->getRegionCode() ?: $defaultBilling->getRegion(),
                            'postcode'   => $defaultBilling->getPostcode(),
                            'country_id' => $defaultBilling->getCountryId(),
                            'telephone'  => $defaultBilling->getTelephone(),
                        ];
                        foreach ($personalMap as $prefillKey => $value) {
                            if (($data[$prefillKey] ?? '') === '') {
                                $data[$prefillKey] = $val($value);
                            }
                        }
                    }
                }
            }

            // Fallback for everyone — including guests, who never get a row in
            // insead_mg_organization / insead_company_address, and a logged-in
            // customer whose prior order under this email was placed as a
            // guest: backfill any still-empty tax/org + address fields from
            // the MOST RECENT placed order with the same email (if multiple
            // past orders share this email, the latest one wins).
            $email = $data['email'] ?? '';
            if ($email !== '') {
                foreach ($this->organizationLookup->findByEmail($email) as $field => $value) {
                    if (($data[$field] ?? '') === '') {
                        $data[$field] = $val($value);
                    }
                }
                foreach ($this->organizationLookup->findAddressByEmail($email) as $field => $value) {
                    if (($data[$field] ?? '') === '') {
                        $data[$field] = $val($value);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout prefill: ' . $e->getMessage());
        }
        return $data;
    }

    /** Whether the programme is held in Singapore (drives the GST Declaration). */
    private function isProgrammeInSingapore(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'insead_checkout/insead_customcheckout/programme_in_singapore',
            ScopeInterface::SCOPE_STORE
        );
    }
}
