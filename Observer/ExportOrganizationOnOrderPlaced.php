<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Insead\CustomCheckout\Model\MgOrganizationWriter;
use Insead\CustomCheckout\Model\CompanyProfile;
use Insead\CustomCheckout\Model\CustomerAddressSaver;
use Psr\Log\LoggerInterface;

/**
 * Fires all of this module's "reuse for next time" writes once an order has
 * actually been placed — never earlier, since the quote it came from could
 * still be abandoned:
 *   - `insead_mg_organization` (DataHub bronze-layer feed)
 *   - B2B: the company's default billing address + customer<->company link
 *     (insead_company_address / insead_company_customer), so a returning
 *     logged-in customer's company address prefills next time
 *   - B2C: the buyer's personal billing address written back to their
 *     native Magento customer address book (default billing)
 *
 * `checkout_submit_all_after` is Magento's own event, dispatched by
 * Magento\Quote\Model\QuoteManagement::submit() for every order placement —
 * native checkout's place-order action, admin-created orders, REST/GraphQL,
 * all of them — so this fires no matter which payment method or code path
 * placed the order. All three writes used to fire from Controller\Billing\Save
 * (before payment, on the quote); moved here so a quote that never becomes
 * an order never leaves reuse data behind.
 */
class ExportOrganizationOnOrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly MgOrganizationWriter $mgOrganizationWriter,
        private readonly CompanyProfile $companyProfile,
        private readonly CustomerAddressSaver $customerAddressSaver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var OrderInterface|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId()) {
            return;
        }

        try {
            $this->mgOrganizationWriter->saveFromOrder($order);
        } catch (\Throwable $e) {
            // Non-fatal: the order itself is already placed; the flat-table
            // export feeds the BI warehouse and can be backfilled separately.
            $this->logger->error('INSEAD mg_organization export (order placed): ' . $e->getMessage());
        }

        $customerId = (int) $order->getCustomerId();
        if ($customerId <= 0) {
            // Guest order — neither reuse table applies (both are keyed by
            // customer_id; there is nothing to prefill for a guest's next visit).
            return;
        }

        $billing = $order->getBillingAddress();
        if (!$billing) {
            return;
        }
        $street = $billing->getStreet();
        $street = is_array($street) ? $street : (array) $street;

        $financingProfile = (string) $order->getData('financing_profile');

        if ($financingProfile === 'b2b') {
            try {
                $this->companyProfile->save($customerId, [
                    'firstname'  => (string) ($order->getData('invoice_recipient_firstname') ?: $billing->getFirstname()),
                    'lastname'   => (string) ($order->getData('invoice_recipient_lastname') ?: $billing->getLastname()),
                    'email'      => (string) ($order->getData('invoice_email') ?: $billing->getEmail()),
                    'company'    => (string) $billing->getCompany(),
                    'street1'    => (string) ($street[0] ?? ''),
                    'street2'    => (string) ($street[1] ?? ''),
                    'city'       => (string) $billing->getCity(),
                    'region'     => (string) ($billing->getRegionCode() ?: $billing->getRegion()),
                    'postcode'   => (string) $billing->getPostcode(),
                    'country_id' => (string) $billing->getCountryId(),
                    'telephone'  => (string) $billing->getTelephone(),
                    'vat_id'     => (string) $billing->getVatId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('INSEAD company profile save (order placed): ' . $e->getMessage());
            }
        } elseif ($financingProfile === 'b2c') {
            try {
                $this->customerAddressSaver->save($customerId, [
                    'firstname'  => (string) $billing->getFirstname(),
                    'lastname'   => (string) $billing->getLastname(),
                    'street1'    => (string) ($street[0] ?? ''),
                    'street2'    => (string) ($street[1] ?? ''),
                    'city'       => (string) $billing->getCity(),
                    'region'     => (string) ($billing->getRegionCode() ?: $billing->getRegion()),
                    'postcode'   => (string) $billing->getPostcode(),
                    'country_id' => (string) $billing->getCountryId(),
                    'telephone'  => (string) $billing->getTelephone(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('INSEAD B2C customer address save (order placed): ' . $e->getMessage());
            }
        }
    }
}

