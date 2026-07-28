<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Insee;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the INSEE Sirene API settings added by etc/adminhtml/system.xml
 * under Stores > Configuration > INSEAD > Checkout > France Company Lookup
 * (INSEE). api_key has backend_model Backend\Encrypted, which encrypts the
 * value on save but does NOT auto-decrypt it on read — ScopeConfigInterface
 * returns the raw ciphertext regardless of backend_model. getApiKey() must
 * explicitly decrypt it via EncryptorInterface, same as core payment/API
 * integrations do for their own obscure fields.
 */
class Config
{
    private const XML_PATH_ENABLED = 'insead_checkout/insee_lookup/enabled';
    private const XML_PATH_API_KEY = 'insead_checkout/insee_lookup/api_key';
    private const XML_PATH_BASE_URL = 'insead_checkout/insee_lookup/api_base_url';
    private const DEFAULT_BASE_URL = 'https://api.insee.fr/api-sirene/3.11';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
        return $encrypted !== '' ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getBaseUrl(?int $storeId = null): string
    {
        $url = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
    }
}
