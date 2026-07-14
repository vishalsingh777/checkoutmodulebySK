<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Writes the organization/tax fields captured at checkout into the flat
 * `insead_mg_organization` table, mirroring the structure of the BI
 * bronze.mg_organization staging table so it can be picked up by the
 * downstream data warehouse export/ETL job.
 *
 * Keyed by customer_id (one row per customer, not per order) — a later order
 * from the same customer upserts/refreshes their single row rather than
 * creating a new one. Guest checkouts (no customer_id) are intentionally
 * never written here.
 */
class MgOrganizationWriter
{
    private const TABLE = 'insead_mg_organization';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    public function saveFromOrder(OrderInterface $order): void
    {
        $customerId = $order->getCustomerId();
        if (!$customerId) {
            return;
        }
        $billingAddress = $order->getBillingAddress();
        $this->write((int) $customerId, $order, $billingAddress ? $billingAddress->getCountryId() : null);
    }

    private function write(int $customerId, DataObject $source, ?string $countryId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $now = $this->dateTime->gmtDate();

        $row = [
            'organization_system_id' => $customerId,
            'organization_legal_name' => $source->getData('company_legal_name'),
            'business_registration_number' => $source->getData('uen'),
            'tax_registration_number' => $source->getData('tax_id_number'),
            'tax_registration_country' => $countryId,
            'tax_registration_status' => $source->getData('tax_registration_status'),
            'tax_exemption_letter_yn' => $source->getData('tax_exempt_file') ? 'Y' : 'N',
            'duns_number' => $source->getData('duns_number'),
            'certification_number' => $source->getData('certificate_id'),
            'sic_code' => $source->getData('sector_of_activity'),
            'status' => 'A',
            'creation_date' => $now,
            'last_update_date' => $now,
            'insert_date' => $now,
            'update_date' => $now,
        ];

        // creation_date and organization_system_id are set only on insert,
        // never overwritten on a subsequent upsert from quote -> order.
        $updateColumns = array_diff(array_keys($row), ['organization_system_id', 'creation_date']);
        $connection->insertOnDuplicate($table, $row, $updateColumns);
    }
}
