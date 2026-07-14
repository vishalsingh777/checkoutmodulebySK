<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists the custom billing / financing / tax data onto the quote.
 *
 * Core address fields populate the quote billing address; the e-invoicing
 * fields are written to the dedicated quote columns owned by this module
 * (see etc/db_schema.xml).
 */
class Save implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Custom quote columns this endpoint is allowed to write. */
    private const ALLOWED_COLUMNS = [
        'financing_profile', 'tax_registration_status', 'company_legal_name',
        'commercial_company_name', 'organization_type',
        'uen', 'vat_intracommunity', 'vat_uae', 'trn', 'ein', 'gst_number', 'tax_id_number',
        'certificate_id', 'duns_number', 'routing_address', 'tax_exempt_file',
        'invoice_recipient_firstname', 'invoice_recipient_lastname', 'invoice_email',
        'reg_type_label', 'reg_type_value', 'gender', 'nationality', 'po_number',
        'job_industry', 'credit_consumption_code', 'gst_declaration', 'residency_declaration',
        'peoplesoft_id',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EavConfig $eavConfig,
        private readonly UrlInterface $url,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || !$quote->hasItems()) {
                return $result->setData(['success' => false, 'message' => __('Your cart is empty.')]);
            }

            $p = $this->request->getParams();

            // Billing address. The quote is normally pre-filled by the
            // Ewave_InseadIntegration "populate" flow, and the Salesforce-style form
            // omits name/phone for Self-funded buyers — so each field resolves as
            // posted value → existing (populated) value → customer/placeholder. This
            // never clobbers populated data with blanks. Magento requires
            // firstname/lastname/telephone/postcode on the address.
            $customer = $this->customerSession->isLoggedIn() ? $this->customerSession->getCustomer() : null;
            $billing = $quote->getBillingAddress();
            $existingStreet = $billing->getStreet();
            $existingStreet = is_array($existingStreet) ? $existingStreet : [];

            $posted = static fn (string $key): string => trim((string) ($p[$key] ?? ''));

            $firstname = $posted('firstname') ?: (string) $billing->getFirstname()
                ?: ($customer ? (string) $customer->getFirstname() : '') ?: 'INSEAD';
            $lastname  = $posted('lastname') ?: (string) $billing->getLastname()
                ?: ($customer ? (string) $customer->getLastname() : '') ?: 'Participant';
            $email     = $posted('email') ?: (string) $quote->getCustomerEmail() ?: (string) $billing->getEmail()
                ?: ($customer ? (string) $customer->getEmail() : '');
            $telephone = $posted('telephone');
            if ($telephone === '') {
                $existing = (string) $billing->getTelephone();
                if ($existing !== '' && $existing !== '0000000000') {
                    $telephone = $existing;
                }
            }
            if ($telephone === '') {
                $telephone = '0000000000';
            }

            $street1 = $posted('street1') ?: (string) ($existingStreet[0] ?? '');
            $street2 = $posted('street2') ?: (string) ($existingStreet[1] ?? '');
            $city    = $posted('city') ?: (string) $billing->getCity() ?: 'N/A';
            $region  = $posted('region') ?: (string) $billing->getRegion();
            $postcode = $posted('postcode') ?: (string) $billing->getPostcode() ?: '00000';
            $countryId = $posted('country_id') ?: (string) $billing->getCountryId() ?: 'FR';

            $billing->addData([
                'firstname'  => $firstname,
                'lastname'   => $lastname,
                'email'      => $email,
                'telephone'  => $telephone,
                'street'     => array_filter([$street1, $street2]),
                'city'       => $city,
                'region'     => $region,
                'postcode'   => $postcode,
                'country_id' => $countryId,
            ]);

            // Prevent native Magento from independently deciding to save this
            // address into the customer's own address book
            // (customer_address_entity) when the order is placed. This
            // module owns ALL address-reuse writes, keyed correctly by
            // financing_profile — B2C goes to the customer's address book
            // (Model\CustomerAddressSaver), B2B goes to insead_company_address
            // (Model\CompanyProfile) — both fired post-order from
            // Observer\ExportOrganizationOnOrderPlaced. Without this flag,
            // native's own order-placement flow can ALSO save the address to
            // customer_address_entity regardless of what this module does,
            // which is what put a B2B company address there instead of the
            // organization tables.
            $billing->setSaveInAddressBook(false);

            // B2B only: `company` is the gate Ewave_CustomerVat's auto customer-group
            // assignment requires to be non-empty before it runs at all (see
            // ewave_customervat/address_attribute/assign_by_filled_attributes config).
            // Without it, the VAT-based group switch never fires and the quote stays
            // on its default group, which can land tax calculation on the wrong rate.
            // `vat_id` is what that mechanism validates against VIES — map it from
            // whichever Tax & Legal field actually holds the registration number for
            // the selected country group (only one of these is populated per order).
            $companyName = $posted('company_legal_name');
            if ($companyName !== '') {
                $billing->setCompany($companyName);
            }
            $vatId = $posted('vat_intracommunity') ?: $posted('vat_uae') ?: $posted('uen') ?: $posted('tax_id_number');
            if ($vatId !== '') {
                $billing->setVatId($vatId);
            }

            // Guest vs. logged-in: a quote without a logged-in customer needs the
            // guest flags + a valid email set, or order placement fails address
            // validation. (Production runs behind the INSEAD portal login, so the
            // email normally comes from the account; B2B always supplies one.)
            if ($this->customerSession->isLoggedIn()) {
                $quote->setCustomerId((int) $customer->getId());
                if ($email !== '') {
                    $quote->setCustomerEmail($email);
                }
            } else {
                $quote->setCustomerId(null)
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID)
                    ->setCheckoutMethod('guest');
                if ($email !== '') {
                    $quote->setCustomerEmail($email);
                }
            }

            // Custom e-invoicing columns.
            foreach (self::ALLOWED_COLUMNS as $col) {
                if (array_key_exists($col, $p)) {
                    $quote->setData($col, $p[$col]);
                }
            }

            // Reserve the order increment id early so the flat-table export row
            // (written now, from the quote) lands under the same key the order
            // will use once native checkout places it.
            if (!$quote->getReservedOrderId()) {
                $quote->reserveOrderId();
            }
            // collectTotals (not just save) so tax recalculates against the
            // billing country/region just submitted. Persisted via
            // CartRepositoryInterface — NOT $quote->save() — because the
            // legacy Quote::save() does not reliably cascade nested billing
            // address changes to the DB (most visible on B2B/first-time
            // saves, where the address row is being inserted rather than
            // updated; native checkout then reloads the quote and shows a
            // stale address). This is the same persistence path native
            // Magento's own checkout uses internally.
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // Persist peoplesoft_id and gender on the customer record so
            // they appear in the admin customer account info page and are
            // available on every future checkout.
            if ($this->customerSession->isLoggedIn()) {
                $customerId = (int) $this->customerSession->getCustomerId();
                $psId       = trim((string) ($p['peoplesoft_id'] ?? ''));
                $genderStr  = strtolower(trim((string) ($p['gender'] ?? '')));

                try {
                    $conn = $this->resourceConnection->getConnection();

                    // Write flat column for our own DB queries.
                    if ($psId !== '') {
                        $conn->update(
                            $this->resourceConnection->getTableName('customer_entity'),
                            ['peoplesoft_id' => $psId],
                            ['entity_id = ?' => $customerId]
                        );
                    }

                    // Write EAV attributes so the admin customer account page
                    // reflects the latest values from checkout.
                    $customerEav = $this->customerRepository->getById($customerId);
                    $dirty = false;

                    if ($psId !== '') {
                        $customerEav->setCustomAttribute('peoplesoft_id', $psId);
                        $dirty = true;
                    }

                    // Map our 'male'/'female' string to Magento's gender option ID.
                    if ($genderStr !== '') {
                        $genderOptionId = $this->resolveGenderOptionId($genderStr);
                        if ($genderOptionId !== null) {
                            $customerEav->setGender($genderOptionId);
                            $dirty = true;
                        }
                    }

                    if ($dirty) {
                        $this->customerRepository->save($customerEav);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('INSEAD customer attribute save: ' . $e->getMessage());
                }
            }

            // Organization/company/customer-address reuse writes intentionally
            // do NOT happen here. They fire only after the order is actually
            // placed (see Observer\ExportOrganizationOnOrderPlaced) — this
            // quote may still be abandoned, and writing insead_mg_organization,
            // insead_company_address, or the customer's default billing
            // address at billing-save time would create/overwrite reuse data
            // for an order that was never confirmed.

            // Mark this quote's Billing Information as confirmed and persist
            // it. The NEXT /checkout page load (the browser is redirected
            // there below) is picked up by Observer\AddCustomCheckoutLayoutHandle,
            // which sees this flag and stops adding the custom-checkout
            // layout handle — so that load is genuine, untouched native
            // Magento checkout, landing straight on the payment step since
            // the quote is virtual. This is what gives every enabled payment
            // method (Stripe, Braintree, PayPal, Sogecommerce, offline
            // methods, anything enabled later) real, zero-maintenance
            // support: native renders and completes each one itself.
            $quote->setData('insead_billing_confirmed', 1);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            return $result->setData([
                'success'  => true,
                'redirect' => $this->url->getUrl('checkout'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout save: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to save billing data.'),
            ]);
        }
    }

    /**
     * Returns Magento's EAV option ID for a gender string sent by the checkout
     * JS ('male', 'female', 'nonbinary', 'prefer_not'). Looks up the live option
     * table so we don't hardcode IDs that vary per installation. Returns null
     * when the value is unknown.
     */
    private function resolveGenderOptionId(string $genderStr): ?int
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                $attribute = $this->eavConfig->getAttribute(\Magento\Customer\Model\Customer::ENTITY, 'gender');
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    $map[strtolower((string) $option['label'])] = (int) $option['value'];
                }
            } catch (\Throwable $e) {
                $this->logger->error('INSEAD gender option lookup: ' . $e->getMessage());
            }
        }

        // The JS gender <select> uses short codes that differ from the EAV label
        // strings: 'nonbinary' → 'non binary', 'prefer_not' → 'prefer not to answer'.
        // Normalize before the map lookup so all four options save correctly.
        $jsToLabel = [
            'nonbinary'  => 'non binary',
            'prefer_not' => 'prefer not to answer',
        ];
        $lookup = $jsToLabel[$genderStr] ?? $genderStr;

        return $map[$lookup] ?? null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }
}
