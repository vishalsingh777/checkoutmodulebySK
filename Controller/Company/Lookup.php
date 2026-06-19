<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Company;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Proxy endpoint for company legal-name auto-fill.
 *
 * In production this calls the INSEE API (France, by SIRET) or the VIES API
 * (EU, by VAT number). Here it returns a deterministic mock so the front-end
 * auto-fill flow is fully wired and testable. Swap _resolveLegalName() for the
 * real HTTP calls when credentials are available.
 */
class Lookup implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $registration = preg_replace('/\s+/', '', (string) $this->request->getParam('registration'));
        $country = (string) $this->request->getParam('country');
        $result = $this->resultJsonFactory->create();

        if ($registration === '') {
            return $result->setData(['legalName' => null]);
        }

        return $result->setData([
            'registration' => $registration,
            'country'      => $country,
            'legalName'    => $this->resolveLegalName($registration, $country),
            'source'       => $country === 'EU' ? 'VIES' : 'INSEE',
            'mock'         => true,
        ]);
    }

    private function resolveLegalName(string $registration, string $country): string
    {
        // Deterministic placeholder until INSEE/VIES integration is enabled.
        $suffix = substr($registration, -4);
        return sprintf('Verified Company %s SAS', $suffix);
    }
}
