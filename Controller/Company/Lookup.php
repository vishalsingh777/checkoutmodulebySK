<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Company;

use Insead\CustomCheckout\Model\Insee\SireneClient;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Proxy endpoint for company auto-fill on the billing form.
 *
 * France (SIRET): looks up the INSEE Sirene API via SireneClient — see
 * Model\Insee\SireneClient / Model\Insee\Config, backed by Stores >
 * Configuration > INSEAD > Checkout > France Company Lookup (INSEE) — and
 * returns legal name, trade name, and postal address.
 *
 * Every other country: no live lookup is wired yet (VIES doesn't return a
 * company name/address the way INSEE does), so this returns an empty
 * payload and the front-end simply leaves those fields for manual entry,
 * same as if auto-fill were unavailable.
 */
class Lookup implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SireneClient $sireneClient,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $registration = preg_replace('/\s+/', '', (string) $this->request->getParam('registration'));
        $country = (string) $this->request->getParam('country');
        $result = $this->resultJsonFactory->create();

        if ($registration === '' || $country !== 'FR') {
            return $result->setData(['legalName' => null]);
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $data = $this->sireneClient->lookupBySiret($registration, $storeId);

        if ($data === null) {
            return $result->setData(['legalName' => null]);
        }

        return $result->setData([
            'registration' => $registration,
            'country'      => $country,
            'legalName'    => $data['legalName'] !== '' ? $data['legalName'] : null,
            'tradeName'    => $data['tradeName'],
            'street1'      => $data['street1'],
            'street2'      => $data['street2'],
            'city'         => $data['city'],
            'postcode'     => $data['postcode'],
            'active'       => $data['active'],
            'source'       => 'INSEE',
        ]);
    }
}
