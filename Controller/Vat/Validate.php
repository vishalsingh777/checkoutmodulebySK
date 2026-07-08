<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Vat;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Customer\Model\Vat;
use Magento\Store\Model\StoreManagerInterface;
use Ewave\CustomerVat\Helper\AddressAttributeConfig;
use Psr\Log\LoggerInterface;

/**
 * Validates a VAT Intracommunity Number against the EU VIES service using
 * Magento's own built-in VAT validator (Magento\Customer\Model\Vat) — the
 * same mechanism behind Stores > Configuration > Customers > Customer
 * Configuration > Create New Account Options auto-group-assignment, and the
 * one Ewave_CustomerVat's own VatValidator wraps for the customer-group
 * switch. Reused directly here rather than writing custom VAT format regex.
 *
 * Gated behind Ewave_CustomerVat's own "Enable Automatic Assignment to
 * Customer Group" toggle (ewave_customervat/address_attribute/
 * auto_group_assign) so a store that has that mechanism turned off doesn't
 * get live VAT validation either — one config, not two overlapping ones.
 */
class Validate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly Vat $vat,
        private readonly StoreManagerInterface $storeManager,
        private readonly AddressAttributeConfig $vatConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $countryId = strtoupper(trim((string) $this->request->getParam('country_id', '')));
        $vatNumber = trim((string) $this->request->getParam('vat_number', ''));

        if ($countryId === '' || $vatNumber === '') {
            return $result->setData([
                'success' => false,
                'valid'   => false,
                'message' => __('Enter a country and VAT number to validate.'),
            ]);
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            if (!$this->vatConfig->isAutoGroupAssignEnabled($storeId)) {
                // VAT-based processing is off for this store (admin setting) —
                // silently skip rather than validating against its wishes.
                return $result->setData(['success' => true, 'valid' => null, 'message' => '']);
            }

            $merchantCountryCode = $this->vat->getMerchantCountryCode($storeId);
            $merchantVatNumber = $this->vat->getMerchantVatNumber($storeId);

            $response = $this->vat->checkVatNumber(
                $countryId,
                $vatNumber,
                $merchantVatNumber !== '' ? $merchantCountryCode : '',
                $merchantVatNumber
            );

            if (!$response->getRequestSuccess()) {
                return $result->setData([
                    'success' => true,
                    'valid'   => null,
                    'message' => (string) ($response->getRequestMessage()
                        ?: __('The VAT validation service is currently unavailable. Please try again later.')),
                ]);
            }

            return $result->setData([
                'success' => true,
                'valid'   => (bool) $response->getIsValid(),
                'message' => $response->getIsValid()
                    ? __('VAT number is valid.')
                    : __('VAT number is not valid.'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout VAT validate: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'valid'   => null,
                'message' => __('Unable to validate the VAT number right now.'),
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
