<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Saved-company reuse for the B2B (Sponsored) checkout. Persists and reads the
 * customer -> company link (insead_company_customer) and the company's default
 * billing address (insead_company_address), so a returning logged-in customer
 * gets their company address pre-filled.
 *
 * The company master is insead_mg_organization, keyed by organization_system_id
 * (= the customer_id for a checkout-created company). The link table formalises
 * the customer<->company relationship and allows the many-to-many case.
 */
class CompanyProfile
{
    private const T_LINK = 'insead_company_customer';
    private const T_ADDRESS = 'insead_company_address';

    /** insead_company_address columns we read/write (excludes keys/timestamps). */
    private const ADDRESS_FIELDS = [
        'firstname', 'lastname', 'email', 'company',
        'street1', 'street2', 'city', 'region', 'postcode', 'country_id',
        'telephone', 'vat_id',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Upsert the customer<->company link and the company's default billing
     * address. Called on a B2B billing save for a logged-in customer.
     *
     * @param array<string,mixed> $address
     */
    public function save(int $customerId, array $address): void
    {
        if ($customerId <= 0) {
            return;
        }
        $organizationId = $customerId; // company id = customer id for checkout-created companies
        $connection = $this->resourceConnection->getConnection();

        // 1) Link (unique on customer_id + organization_system_id).
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::T_LINK),
            ['customer_id' => $customerId, 'organization_system_id' => $organizationId],
            ['organization_system_id']
        );

        // 2) Default address — update the existing default row, else insert.
        $addressTable = $this->resourceConnection->getTableName(self::T_ADDRESS);
        $row = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $row[$field] = isset($address[$field]) ? (string) $address[$field] : null;
        }

        $existingId = $connection->fetchOne(
            $connection->select()
                ->from($addressTable, 'address_id')
                ->where('organization_system_id = ?', $organizationId)
                ->where('is_default = ?', 1)
                ->limit(1)
        );

        if ($existingId) {
            $connection->update($addressTable, $row, ['address_id = ?' => (int) $existingId]);
        } else {
            $row['organization_system_id'] = $organizationId;
            $row['is_default'] = 1;
            $connection->insert($addressTable, $row);
        }
    }

    /**
     * The customer's default company billing address (via the link table),
     * mapped to checkout-form field names, for prefill. Empty if none saved.
     *
     * @return array<string,string>
     */
    public function findDefaultAddressByCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['a' => $this->resourceConnection->getTableName(self::T_ADDRESS)], self::ADDRESS_FIELDS)
            ->join(
                ['l' => $this->resourceConnection->getTableName(self::T_LINK)],
                'l.organization_system_id = a.organization_system_id',
                []
            )
            ->where('l.customer_id = ?', $customerId)
            ->where('a.is_default = ?', 1)
            ->order('a.updated_at DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return [];
        }
        return array_filter(
            array_map(static fn ($v) => $v === null ? '' : (string) $v, $row),
            static fn ($v) => $v !== ''
        );
    }
}
