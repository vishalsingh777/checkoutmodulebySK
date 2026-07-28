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
use Psr\Log\LoggerInterface;

/**
 * Validates a VAT Intracommunity Number against the EU VIES service using
 * only core Magento (Magento\Customer\Model\Vat — module-customer). No
 * dependency on any third-party module or store-level toggle: this always
 * runs when called, for any EU country code Vat::checkVatNumber() accepts.
 */
class Validate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly Vat $vat,
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
                'valid'   => null,
                'message' => __('Enter a country and VAT number to validate.'),
            ]);
        }

        if (!$this->vat->isCountryInEU($countryId)) {
            return $result->setData([
                'success' => false,
                'valid'   => null,
                'message' => __('VAT validation is only available for EU countries.'),
            ]);
        }

        try {
            $response = $this->vat->checkVatNumber($countryId, $vatNumber);

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
