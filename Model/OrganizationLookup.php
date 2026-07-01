<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Looks up the organization/tax data previously captured for a customer, used
 * to prefill the checkout form for a returning customer so they don't have to
 * re-enter company/tax details already captured on a prior order.
 *
 * findByCustomerId() reads insead_mg_organization, which is keyed directly by
 * customer_id (one row per logged-in customer) — a direct lookup, no join
 * needed. Guests are never written there, so findByEmail() / findAddressByEmail()
 * cover that case (and a logged-in customer whose prior order under this
 * email was placed as a guest) by reading straight off the most recent
 * sales_order row with a matching customer_email — if there are several past
 * orders under the same email, the latest one (ORDER BY created_at DESC) wins.
 */
class OrganizationLookup
{
    /** Quote/billing-form field name => insead_mg_organization column. */
    private const FIELD_MAP = [
        'company_legal_name' => 'organization_legal_name',
        'uen' => 'business_registration_number',
        'tax_id_number' => 'tax_registration_number',
        'tax_registration_status' => 'tax_registration_status',
        'duns_number' => 'duns_number',
        'certificate_id' => 'certification_number',
        'sector_of_activity' => 'sic_code',
    ];

    /** Quote/billing-form fields also stored directly on sales_order (same column name). */
    private const ORDER_FIELDS = [
        'financing_profile', 'tax_registration_status', 'company_legal_name',
        'commercial_company_name', 'organization_type', 'sector_of_activity',
        'uen', 'vat_intracommunity', 'vat_uae', 'gst_number', 'tax_id_number',
        'certificate_id', 'duns_number', 'routing_address', 'po_number',
        'invoice_recipient_firstname', 'invoice_recipient_lastname', 'invoice_email',
        'reg_type_label', 'reg_type_value', 'gender', 'nationality', 'job_industry',
        'tax_exempt_file',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string,string> Quote-field-name => value, only for non-null matches.
     */
    public function findByCustomerId(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orgTable = $this->resourceConnection->getTableName('insead_mg_organization');

        $select = $connection->select()
            ->from($orgTable, array_values(self::FIELD_MAP))
            ->where('organization_system_id = ?', $customerId);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return [];
        }

        $result = [];
        foreach (self::FIELD_MAP as $quoteField => $orgColumn) {
            if (isset($row[$orgColumn]) && $row[$orgColumn] !== '') {
                $result[$quoteField] = $row[$orgColumn];
            }
        }
        return $result;
    }

    /**
     * Backfill source for guest checkouts (and logged-in customers whose
     * prior order was placed as a guest): the MOST RECENT sales_order with
     * the same email, regardless of customer_id. If several past orders
     * share this email, only the latest (by created_at) is used.
     *
     * @return array<string,string> Quote-field-name => value, only for non-null matches.
     */
    public function findByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from($orderTable, self::ORDER_FIELDS)
            ->where('customer_email = ?', $email)
            ->order('created_at DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return [];
        }

        $result = [];
        foreach ($row as $field => $value) {
            if ($value !== null && $value !== '') {
                $result[$field] = $value;
            }
        }
        return $result;
    }

    /**
     * Billing address from the most recent order with the same email —
     * companion to findByEmail(), used to backfill the address fields for a
     * guest (or a logged-in customer whose prior order under this email was
     * a guest order, so it has no saved customer address to fall back on).
     * Same "latest wins" rule as findByEmail().
     *
     * @return array<string,string> Checkout-form field-name => value, only for non-empty matches.
     */
    public function findAddressByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $addressTable = $this->resourceConnection->getTableName('sales_order_address');

        $select = $connection->select()
            ->from(['o' => $orderTable], [])
            ->join(
                ['a' => $addressTable],
                'a.parent_id = o.entity_id AND a.address_type = \'billing\'',
                ['firstname', 'lastname', 'street', 'city', 'region', 'postcode', 'country_id', 'telephone']
            )
            ->where('o.customer_email = ?', $email)
            ->order('o.created_at DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return [];
        }

        $street = explode("\n", (string) ($row['street'] ?? ''));
        $result = [
            'firstname'  => $row['firstname'] ?? '',
            'lastname'   => $row['lastname'] ?? '',
            'street1'    => $street[0] ?? '',
            'street2'    => $street[1] ?? '',
            'city'       => $row['city'] ?? '',
            'region'     => $row['region'] ?? '',
            'postcode'   => $row['postcode'] ?? '',
            'country_id' => $row['country_id'] ?? '',
            'telephone'  => $row['telephone'] ?? '',
        ];
        return array_filter($result, static fn ($v) => $v !== null && $v !== '');
    }
}
