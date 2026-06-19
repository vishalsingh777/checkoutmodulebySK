<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
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
        'commercial_company_name', 'organization_type', 'sector_of_activity',
        'uen', 'vat_intracommunity', 'vat_uae', 'gst_number', 'tax_id_number',
        'certificate_id', 'duns_number', 'routing_address', 'tax_exempt_file',
        'invoice_recipient_firstname', 'invoice_recipient_lastname', 'invoice_email',
        'reg_type_label', 'reg_type_value', 'gender', 'nationality', 'po_number',
        'job_industry', 'credit_consumption_code', 'gst_declaration', 'residency_declaration',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
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
            $telephone = $posted('telephone') ?: (string) $billing->getTelephone() ?: '0000000000';

            $street1 = $posted('street1') ?: (string) ($existingStreet[0] ?? '');
            $street2 = $posted('street2') ?: (string) ($existingStreet[1] ?? '');
            $city    = $posted('city') ?: (string) $billing->getCity();
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

            $quote->save();

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout save: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to save billing data.'),
            ]);
        }
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
