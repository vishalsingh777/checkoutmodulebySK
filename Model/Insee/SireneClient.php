<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Insee;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Looks up a French establishment by SIRET via the INSEE Sirene API
 * (GET {base_url}/siret/{siret}, header X-INSEE-Api-Key-Integration), used to
 * auto-fill the Organisation Details / Postal Address fields on the France
 * billing form. Settings (enable flag, API key, base URL) come from Config,
 * backed by Stores > Configuration > INSEAD > Checkout > France Company
 * Lookup (INSEE).
 *
 * Every failure mode (disabled, malformed SIRET, missing key, INSEE down,
 * 404/401/429/5xx, malformed response) returns null rather than throwing —
 * this is a convenience auto-fill on top of a field the shopper can always
 * fill in by hand, never something allowed to break checkout.
 */
class SireneClient
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     siret: string, siren: string, legalName: string, tradeName: string,
     *     street1: string, street2: string, city: string, postcode: string,
     *     active: bool, headOffice: bool, nafCode: string
     * }|null Null when disabled, invalid input, or the lookup didn't succeed.
     */
    public function lookupBySiret(string $siret, ?int $storeId = null): ?array
    {
        if (!$this->config->isEnabled($storeId)) {
            return null;
        }

        $siret = preg_replace('/\s+/', '', $siret) ?? '';
        if (!preg_match('/^\d{14}$/', $siret)) {
            return null;
        }

        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            $this->logger->warning('INSEAD INSEE lookup: SIRET auto-fill is enabled but no API key is configured.');
            return null;
        }

        $url = $this->config->getBaseUrl($storeId) . '/siret/' . $siret;

        try {
            $this->curl->setTimeout(self::TIMEOUT_SECONDS);
            $this->curl->addHeader('X-INSEE-Api-Key-Integration', $apiKey);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get($url);

            $status = (int) $this->curl->getStatus();
            $body = (string) $this->curl->getBody();

            if ($status === 404) {
                // Unknown SIRET — not an error, just nothing to auto-fill.
                return null;
            }
            if ($status !== 200) {
                $this->logger->error(sprintf('INSEAD INSEE lookup: HTTP %d for SIRET %s.', $status, $siret));
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || !isset($data['etablissement']) || !is_array($data['etablissement'])) {
                $this->logger->error('INSEAD INSEE lookup: unexpected response shape for SIRET ' . $siret);
                return null;
            }

            return $this->normalize($data['etablissement']);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD INSEE lookup: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string,mixed> $etablissement Raw "etablissement" node of the INSEE response.
     * @return array{
     *     siret: string, siren: string, legalName: string, tradeName: string,
     *     street1: string, street2: string, city: string, postcode: string,
     *     active: bool, headOffice: bool, nafCode: string
     * }
     */
    private function normalize(array $etablissement): array
    {
        $unite = is_array($etablissement['uniteLegale'] ?? null) ? $etablissement['uniteLegale'] : [];
        $adresse = is_array($etablissement['adresseEtablissement'] ?? null) ? $etablissement['adresseEtablissement'] : [];

        $streetParts = array_filter([
            $adresse['numeroVoieEtablissement'] ?? null,
            $adresse['typeVoieEtablissement'] ?? null,
            $adresse['libelleVoieEtablissement'] ?? null,
        ], static fn ($part) => $part !== null && $part !== '');

        return [
            'siret' => (string) ($etablissement['siret'] ?? ''),
            'siren' => (string) ($etablissement['siren'] ?? ''),
            'legalName' => (string) ($unite['denominationUniteLegale'] ?? ''),
            'tradeName' => (string) ($unite['denominationUsuelle1UniteLegale'] ?? ''),
            'street1' => implode(' ', $streetParts),
            'street2' => (string) ($adresse['complementAdresseEtablissement'] ?? ''),
            'city' => (string) ($adresse['libelleCommuneEtablissement'] ?? ''),
            'postcode' => (string) ($adresse['codePostalEtablissement'] ?? ''),
            'active' => ($unite['etatAdministratifUniteLegale'] ?? null) === 'A',
            'headOffice' => (bool) ($etablissement['etablissementSiege'] ?? false),
            'nafCode' => (string) ($unite['activitePrincipaleUniteLegale'] ?? ''),
        ];
    }
}
