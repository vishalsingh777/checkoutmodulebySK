<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Payment\Model\MethodList;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use StripeIntegration\Payments\Model\Ui\ConfigProvider as StripeConfigProvider;
use PayPal\Braintree\Model\Ui\ConfigProvider as BraintreeConfigProvider;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Cms\Model\Template\FilterProvider as CmsFilterProvider;
use Ewave\CustomerVat\Helper\AddressAttributeConfig;
use Insead\CustomCheckout\Model\OrganizationLookup;
use Insead\CustomCheckout\Model\CompanyProfile;

/**
 * Bootstrap configuration for the standalone INSEAD two-step checkout
 * (Billing Information + Payment) KnockoutJS component.
 *
 * Because this page removes Magento's native checkout (`checkout.root`),
 * `window.checkoutConfig` no longer exists — so the Stripe publishable key /
 * element options that the Payment Element needs are carried here instead.
 */
class Checkout extends Template
{
    /** Stripe payment-method codes surfaced on the custom payment step. */
    private const STRIPE_CARD = 'stripe_payments';
    private const STRIPE_BANK = 'stripe_payments_bank_transfers';

    /** CMS block rendered in the Declarations card (Content > Blocks). */
    private const DECLARATIONS_CMS_BLOCK = 'checkout-sidebar-text';

    /** Memoised Braintree token (false = not yet resolved; null = inactive/error). */
    private string|null|false $cachedBraintreeToken = false;

    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly MethodList $methodList,
        private readonly FormKey $formKey,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly StripeConfigProvider $stripeConfigProvider,
        private readonly BraintreeConfigProvider $braintreeConfigProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrganizationLookup $organizationLookup,
        private readonly CompanyProfile $companyProfile,
        private readonly GetBlockByIdentifierInterface $cmsBlockByIdentifier,
        private readonly CmsFilterProvider $cmsFilterProvider,
        private readonly AddressAttributeConfig $vatConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Current store code. Emitted as window.checkoutConfig.storeCode so the
     * reused Magento checkout JS models (e.g. url-builder, used by Stripe's
     * get-requires-action) work without the native checkout bootstrap.
     */
    public function getStoreCode(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCode();
        } catch (\Throwable $e) {
            return 'default';
        }
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
            'paymentMethods' => $this->getPaymentMethods(),
            'stripe'         => $this->getStripeConfig(),
            'summary'        => $this->getSummary(),
            'prefill'        => $this->getPrefill(),
            'programmeInSingapore' => $this->isProgrammeInSingapore(),
            'formKey'             => $this->formKey->getFormKey(),
            'braintreeClientToken' => $this->getBraintreeClientToken(),
            'proformaInvoiceEnabled' => $this->isProformaInvoiceEnabled(),
            'declarationsHtml'    => $this->getDeclarationsHtml(),
            'vatValidationEnabled' => $this->isVatValidationEnabled(),
            'urls'                => [
                'lookup'         => $this->getUrl('insead_checkout/company/lookup'),
                'save'           => $this->getUrl('insead_checkout/billing/save'),
                'upload'         => $this->getUrl('insead_checkout/billing/upload'),
                'place'          => $this->getUrl('insead_checkout/order/place'),
                'requiresAction' => $this->getUrl('stripe/payments/get_requires_action'),
                'success'        => $this->getUrl('checkout/onepage/success'),
                'cart'           => $this->getUrl('checkout/cart'),
                'proformaPdf'    => $this->getUrl('proforma/quote/pdf'),
                'applyCoupon'    => $this->getUrl('insead_checkout/coupon/apply'),
                'validateVat'    => $this->getUrl('insead_checkout/vat/validate'),
                'restBase'       => $this->storeManager->getStore()
                    ->getBaseUrl(UrlInterface::URL_TYPE_WEB) . 'rest/V1/',
            ],
        ]);
    }

    /**
     * Whether the "Quotation" (Proforma Invoice) button should be shown on the
     * payment step. Mirrors Insead\ProformaInvoice\Helper\Data::isEnabled() —
     * same config path (Stores > Configuration > Insead > Proforma Invoice),
     * read directly via scopeConfig (already injected here) rather than adding
     * a hard dependency on that module.
     */
    private function isProformaInvoiceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'proforma_invoice/general/enabled',
            ScopeInterface::SCOPE_STORE
        );
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
     * Renders the "checkout-sidebar-text" CMS block (Content > Blocks) for the
     * Declarations card, store-scoped and with widget/template directives
     * filtered the same way Magento\Cms\Block\BlockByIdentifier does. Content
     * is informational only — no acceptance checkbox gates order placement
     * anymore. Returns '' when the block is missing, disabled, or errors.
     */
    private function getDeclarationsHtml(): string
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $block = $this->cmsBlockByIdentifier->execute(self::DECLARATIONS_CMS_BLOCK, $storeId);
            if (!$block->isActive()) {
                return '';
            }
            return $this->cmsFilterProvider->getBlockFilter()
                ->setStoreId($storeId)
                ->filter((string) $block->getContent());
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout declarations block: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Generate the Braintree client token at page-render time.
     * The paypal/module-braintree-core does not expose a REST endpoint for this,
     * so we generate it server-side and embed it directly in the JS config.
     * Returns null when Braintree is inactive or the API call fails.
     *
     * Memoised: getClientToken() makes an HTTP call to Braintree's servers and
     * is called from both getJsonConfig() and the phtml <script defer> guard.
     * Without this cache the double call doubled page-load latency and could
     * trigger PHP max_execution_time on a slow first connection.
     */
    public function getBraintreeClientToken(): ?string
    {
        if ($this->cachedBraintreeToken !== false) {
            return $this->cachedBraintreeToken;
        }
        try {
            $config = $this->braintreeConfigProvider->getConfig();
            $isActive = $config['payment'][BraintreeConfigProvider::CODE]['isActive'] ?? false;
            if (!$isActive) {
                return $this->cachedBraintreeToken = null;
            }
            $token = $this->braintreeConfigProvider->getClientToken();
            return $this->cachedBraintreeToken = is_string($token) ? $token : null;
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD Braintree client token: ' . $e->getMessage());
            return $this->cachedBraintreeToken = null;
        }
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
     * All available payment methods grouped for the payment step.
     * Stripe card + bank are handled via the Payment Element; every other
     * enabled method is surfaced in the `others` list so the checkout can
     * render them (Braintree Drop-in, offline methods, etc.).
     *
     * @return array{card:?array,bank:?array,others:array}
     */
    private function getPaymentMethods(): array
    {
        $available = [];
        try {
            $quote   = $this->checkoutSession->getQuote();
            $storeId = (int) $this->storeManager->getStore()->getId();

            // getAvailableMethods() validates each method against the current quote
            // (amount limits, country restrictions, unconfigured gateways, "free"
            // only when total=0, Stripe Billing needing a customer, etc.) so only
            // truly usable methods are returned. This is multi-store safe because
            // the quote already carries the correct store_id.
            foreach ($this->methodList->getAvailableMethods($quote) as $method) {
                $available[$method->getCode()] = (string) $method->getTitle();
            }

            // For virtual quotes Magento's offline payment methods (COD, bank
            // transfer, check/money order, purchase order) self-exclude via
            // isAvailable($quote->isVirtual()). INSEAD programmes are always
            // virtual, but these offline methods may still be intentionally enabled
            // per store. Re-add them when they are active in the current store scope.
            if ($quote->isVirtual()) {
                foreach (['cashondelivery', 'checkmo', 'banktransfer', 'purchaseorder'] as $code) {
                    if (isset($available[$code])) {
                        continue; // already returned by getAvailableMethods
                    }
                    if ($this->scopeConfig->isSetFlag(
                        'payment/' . $code . '/active',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    )) {
                        $title = (string) $this->scopeConfig->getValue(
                            'payment/' . $code . '/title',
                            ScopeInterface::SCOPE_STORE,
                            $storeId
                        );
                        if ($title !== '') {
                            $available[$code] = $title;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout payment methods: ' . $e->getMessage());
        }

        $stripeExplicit = [self::STRIPE_CARD, self::STRIPE_BANK];
        $others = [];
        foreach ($available as $code => $title) {
            // Skip the two Stripe methods that have their own explicit UI sections.
            if (in_array($code, $stripeExplicit, true)) {
                continue;
            }
            // Skip ALL other stripe_* sub-methods (stripe_payments_express covers
            // Apple Pay + Google Pay, stripe_payments_multishipping, etc.).
            // These are rendered INSIDE the Stripe Payment Element automatically
            // by Stripe's SDK when the browser supports them — they must not appear
            // as separate radio buttons because they have no standalone form UI.
            if (strncmp($code, 'stripe_', 7) === 0) {
                continue;
            }
            // Skip Braintree sub-methods (braintree_cc_vault, braintree_paypal,
            // braintree_googlepay, etc.). The Drop-in UI renders all payment types
            // internally — only the top-level 'braintree' code gets a UI slot.
            if ($code !== 'braintree' && strncmp($code, 'braintree', 9) === 0) {
                continue;
            }
            $others[] = ['code' => $code, 'title' => $title];
        }

        return [
            'card'   => isset($available[self::STRIPE_CARD])
                ? ['code' => self::STRIPE_CARD, 'title' => $available[self::STRIPE_CARD]]
                : null,
            'bank'   => isset($available[self::STRIPE_BANK])
                ? ['code' => self::STRIPE_BANK, 'title' => $available[self::STRIPE_BANK]]
                : null,
            'others' => $others,
        ];
    }

    /**
     * Stripe publishable key + Payment Element options (card + bank transfer),
     * mirrored from StripeIntegration's own ConfigProvider so the Element can
     * mount without the native checkout config.
     *
     * @return array<string,mixed>
     */
    private function getStripeConfig(): array
    {
        try {
            $config = $this->stripeConfigProvider->getConfig();
            $card = $config['payment'][self::STRIPE_CARD] ?? [];
            $bank = $config['payment'][self::STRIPE_BANK] ?? [];
            return [
                'enabled'           => (bool) ($card['enabled'] ?? false),
                'initParams'        => $card['initParams'] ?? null,
                'elementOptions'    => $card['elementOptions'] ?? null,
                'bankElementOptions' => $bank['elementOptions'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout stripe config: ' . $e->getMessage());
            return ['enabled' => false];
        }
    }

    /**
     * Order summary (programme line items + totals) for the payment sidebar.
     *
     * @return array{items:array<int,array<string,mixed>>,totals:array<string,string>,currency:string}
     */
    private function getSummary(): array
    {
        $items = [];
        $totals = ['subtotal' => '', 'discount' => '', 'tax' => '', 'grand' => '', 'grandRaw' => '0.00', 'couponCode' => ''];
        $currency = '';
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $currency = (string) $quote->getQuoteCurrencyCode();
                foreach ($quote->getAllVisibleItems() as $item) {
                    $fee = (float) $item->getPrice();
                    $final = (float) ($item->getRowTotal() ?: ($fee * (float) $item->getQty()));
                    $offering = $fee > 0 && (float) $item->getDiscountAmount() > 0
                        ? round(((float) $item->getDiscountAmount() / ($fee * (float) $item->getQty())) * 100)
                        : 0;
                    $items[] = [
                        'name'     => (string) $item->getName(),
                        'qty'      => (float) $item->getQty(),
                        'fee'      => $this->priceCurrency->format($fee, false),
                        'offering' => (int) $offering . '%',
                        'finalFee' => $this->priceCurrency->format($final, false),
                    ];
                }
                $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
                $totals = [
                    'subtotal'   => $this->priceCurrency->format((float) $quote->getSubtotal(), false),
                    'discount'   => $this->priceCurrency->format(abs((float) $address->getDiscountAmount()), false),
                    'tax'        => $this->priceCurrency->format((float) $address->getTaxAmount(), false),
                    'grand'      => $this->priceCurrency->format((float) $quote->getGrandTotal(), false),
                    // Unformatted amount for the Braintree 3D Secure challenge, which
                    // needs a plain decimal string ("7.00"), not a currency-symbol string.
                    'grandRaw'   => number_format((float) $quote->getGrandTotal(), 2, '.', ''),
                    'couponCode' => (string) $quote->getCouponCode(),
                ];
            }
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout summary: ' . $e->getMessage());
        }
        return ['items' => $items, 'totals' => $totals, 'currency' => $currency];
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
            $b = $quote->getBillingAddress();
            $street = $b->getStreet();
            $street = is_array($street) ? $street : [];

            $val = static fn ($v) => $v === null ? '' : (string) $v;

            $data = [
                // Billing address (set by the populate flow).
                'firstname'  => $val($b->getFirstname()),
                'lastname'   => $val($b->getLastname()),
                'email'      => $val($quote->getCustomerEmail() ?: $b->getEmail()),
                'telephone'  => $val($b->getTelephone()),
                'street1'    => $val($street[0] ?? ''),
                'street2'    => $val($street[1] ?? ''),
                'city'       => $val($b->getCity()),
                'region'     => $val($b->getRegionCode() ?: $b->getRegion()),
                'postcode'   => $val($b->getPostcode()),
                'country_id' => $val($b->getCountryId() ?: $quote->getData('quote_country')),
                'company'    => $val($b->getCompany()),
                'vat_id'     => $val($b->getVatId()),
                'gender'     => $val($quote->getData('gender')),
                'nationality' => $val($quote->getData('nationality')),
                // Profile: Ewave sets is_btob on the quote.
                'is_btob'    => (int) $quote->getData('is_btob'),
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
                'routing_address'    => $val($quote->getData('routing_address')),
                'tax_exempt_file'    => $val($quote->getData('tax_exempt_file')),
                'peoplesoft_id'      => $val($quote->getData('peoplesoft_id')),
            ];

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
                if ((int) $quote->getData('is_btob') === 1) {
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
                }

                // B2C: backfill the buyer's personal billing address from their
                // saved Magento customer address (default billing). Only for non-B2B
                // so a company quote is never overwritten with personal data.
                if ((int) $quote->getData('is_btob') !== 1) {
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
