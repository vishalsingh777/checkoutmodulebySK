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
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use StripeIntegration\Payments\Model\Ui\ConfigProvider as StripeConfigProvider;

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
        private readonly StoreManagerInterface $storeManager,
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
            'formKey'        => $this->formKey->getFormKey(),
            'urls'           => [
                'lookup'         => $this->getUrl('insead_checkout/company/lookup'),
                'save'           => $this->getUrl('insead_checkout/billing/save'),
                'place'          => $this->getUrl('insead_checkout/order/place'),
                'requiresAction' => $this->getUrl('stripe/payments/get_requires_action'),
                'success'        => $this->getUrl('checkout/onepage/success'),
                'cart'           => $this->getUrl('checkout/cart'),
            ],
        ]);
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
     * Card / Bank-transfer availability + titles for the payment-method radios.
     *
     * @return array{card:?array{code:string,title:string},bank:?array{code:string,title:string}}
     */
    private function getPaymentMethods(): array
    {
        $available = [];
        try {
            $quote = $this->checkoutSession->getQuote();
            foreach ($this->methodList->getAvailableMethods($quote) as $method) {
                $available[$method->getCode()] = (string) $method->getTitle();
            }
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout payment methods: ' . $e->getMessage());
        }

        return [
            'card' => isset($available[self::STRIPE_CARD])
                ? ['code' => self::STRIPE_CARD, 'title' => $available[self::STRIPE_CARD]]
                : null,
            'bank' => isset($available[self::STRIPE_BANK])
                ? ['code' => self::STRIPE_BANK, 'title' => $available[self::STRIPE_BANK]]
                : null,
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
        $totals = ['subtotal' => '', 'tax' => '', 'grand' => ''];
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
                    'subtotal' => $this->priceCurrency->format((float) $quote->getSubtotal(), false),
                    'tax'      => $this->priceCurrency->format((float) $address->getTaxAmount(), false),
                    'grand'    => $this->priceCurrency->format((float) $quote->getGrandTotal(), false),
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
            ];
        } catch (\Throwable $e) {
            $this->_logger->error('INSEAD checkout prefill: ' . $e->getMessage());
        }
        return $data;
    }

    /** Whether the programme is held in Singapore (drives the GST Declaration). */
    private function isProgrammeInSingapore(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'checkout/insead_customcheckout/programme_in_singapore',
            ScopeInterface::SCOPE_STORE
        );
    }
}
