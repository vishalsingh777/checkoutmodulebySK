<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;

/**
 * Writes the Self-funded (B2C) buyer's personal billing address back to their
 * Magento customer record (customer_address_entity, via the Customer API) as
 * the default billing address, so it pre-fills their next checkout. Updates the
 * existing default billing address if there is one, otherwise creates it.
 */
class CustomerAddressSaver
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory
    ) {
    }

    /**
     * @param array<string,mixed> $data firstname,lastname,street1,street2,city,region,postcode,country_id,telephone
     */
    public function save(int $customerId, array $data): void
    {
        if ($customerId <= 0) {
            return;
        }
        $customer = $this->customerRepository->getById($customerId);

        $address = null;
        if ($customer->getDefaultBilling()) {
            try {
                $address = $this->addressRepository->getById((int) $customer->getDefaultBilling());
            } catch (\Throwable $e) {
                $address = null;
            }
        }
        if (!$address) {
            $address = $this->addressFactory->create();
        }

        $street = array_values(array_filter(
            [(string) ($data['street1'] ?? ''), (string) ($data['street2'] ?? '')],
            static fn ($v) => $v !== ''
        ));

        $address->setCustomerId($customerId);
        $address->setFirstname((string) ($data['firstname'] ?? ''));
        $address->setLastname((string) ($data['lastname'] ?? ''));
        $address->setStreet($street ?: ['']);
        $address->setCity((string) ($data['city'] ?? ''));
        $address->setPostcode((string) ($data['postcode'] ?? ''));
        $address->setCountryId((string) ($data['country_id'] ?? ''));
        $address->setTelephone((string) ($data['telephone'] ?? ''));

        $regionVal = trim((string) ($data['region'] ?? ''));
        if ($regionVal !== '') {
            $region = $this->regionFactory->create();
            $region->setRegion($regionVal);
            $region->setRegionCode($regionVal);
            $address->setRegion($region);
        }

        $address->setIsDefaultBilling(true);
        if (!$customer->getDefaultShipping()) {
            $address->setIsDefaultShipping(true);
        }

        $this->addressRepository->save($address);
    }
}
