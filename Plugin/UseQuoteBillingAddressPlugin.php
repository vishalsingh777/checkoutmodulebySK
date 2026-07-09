<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Plugin;

use Magento\Checkout\Model\DefaultConfigProvider;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * DefaultConfigProvider::getConfig() returns customerData.addresses — the
 * customer's native address book — which the checkout JS (and Stripe's own
 * billing-address component) auto-selects as the billing address. For our
 * flow this is wrong: the correct address was already written onto the quote
 * by Controller\Billing\Save, and for B2B it is intentionally never mirrored
 * into the address book (B2B goes to insead_company_address instead).
 *
 * Fix: after getConfig(), when insead_billing_confirmed=1, replace
 * customerData.addresses with a single entry built from the quote's own
 * billing address. The checkout JS then has only one address to pick from —
 * the right one — and marks it as the default. billingAddressFromData is
 * also set so it takes effect even before the JS address-resolver runs.
 *
 * Scoped to quotes that went through our step so unrelated flows are
 * completely unaffected.
 */
class UseQuoteBillingAddressPlugin
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function afterGetConfig(DefaultConfigProvider $subject, array $result): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || (int) $quote->getData('insead_billing_confirmed') !== 1) {
                return $result;
            }
            // The ORM's in-memory billing address object can be stale (loaded before
            // Save.php wrote the B2B data in a prior request but not yet invalidated).
            // Read straight from the DB so we always get the data the customer just submitted.
            $conn = $this->resourceConnection->getConnection();
            $row = $conn->fetchRow(
                'SELECT * FROM quote_address WHERE quote_id = ? AND address_type = "billing" LIMIT 1',
                [$quote->getId()]
            );
            if (empty($row)) {
                return $result;
            }

            $streetRaw = $row['street'] ?? '';
            $streetArr = is_array($streetRaw)
                ? $streetRaw
                : array_values(array_filter(explode("\n", (string) $streetRaw)));

            $addr = [
                'id'               => 0,
                'firstname'        => (string) ($row['firstname'] ?? ''),
                'lastname'         => (string) ($row['lastname'] ?? ''),
                'company'          => (string) ($row['company'] ?? ''),
                'street'           => $streetArr ?: [''],
                'city'             => (string) ($row['city'] ?? ''),
                'region_code'      => (string) ($row['region_code'] ?? ''),
                'region'           => [
                    'region'      => (string) ($row['region'] ?? ''),
                    'region_code' => (string) ($row['region_code'] ?? ''),
                    'region_id'   => !empty($row['region_id']) ? (int) $row['region_id'] : null,
                ],
                'postcode'         => (string) ($row['postcode'] ?? ''),
                'country_id'       => (string) ($row['country_id'] ?? ''),
                'telephone'        => (string) ($row['telephone'] ?? ''),
                'email'            => (string) ($row['email'] ?? $quote->getCustomerEmail() ?? ''),
                'default_billing'  => true,
                'default_shipping' => false,
                'save_in_address_book' => 0,
            ];

            // Replace the customer's address book so the checkout JS (and
            // Stripe's billing-address component) can only pick this address.
            if (isset($result['customerData'])) {
                $result['customerData']['addresses'] = [$addr];
            }

            // Also set billingAddressFromData so the JS address resolver
            // picks it up even before the address-list component renders.
            $result['billingAddressFromData'] = $addr;
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout billing address override: ' . $e->getMessage());
        }
        return $result;
    }
}
