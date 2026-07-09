<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model;

use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\MethodList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves available payment methods for the INSEAD checkout.
 *
 * Shared by Block\Checkout (page-load snapshot) and Controller\Billing\Save
 * (post-address refresh). A single class means filtering rules — Stripe,
 * Braintree sub-methods, PayPal redirects — are defined once in etc/di.xml.
 */
class PaymentMethodProvider
{
    public function __construct(
        private readonly MethodList $methodList,
        private readonly PaymentMethodListInterface $paymentMethodList,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        // Configured via etc/di.xml — change gateway identifiers there, not here.
        private readonly string $stripeCard = 'stripe_payments',
        private readonly string $stripeBank = 'stripe_payments_bank_transfers',
        private readonly array $paypalRedirectMethods = [],
        private readonly array $alwaysExcludedCodes = [],
        private readonly array $paymentElementNamespaces = [],
        private readonly array $dropinModelPrefixes = [],
        private readonly array $dropinTopLevelCodes = [],
    ) {
    }

    public function getStripeCardCode(): string
    {
        return $this->stripeCard;
    }

    public function getStripeBankCode(): string
    {
        return $this->stripeBank;
    }

    /**
     * Returns available payment methods grouped for the checkout payment step.
     *
     * Two-pass approach:
     *   Pass 1 — getAvailableMethods($quote): quote-validated (credentials,
     *             country, amount limits, active flag).
     *   Pass 2 — getActiveList($storeId): re-adds any method excluded by quote
     *             constraints (e.g. offline methods self-exclude for virtual carts).
     *
     * Covers all Magento product types without a hardcoded method list.
     *
     * @return array{card:?array,bank:?array,others:array}
     */
    public function getForQuote(\Magento\Quote\Api\Data\CartInterface $quote): array
    {
        $available     = [];
        $methodObjects = []; // code => MethodInterface for class-based detection
        $storeId       = (int) $quote->getStoreId();

        try {
            foreach ($this->methodList->getAvailableMethods($quote) as $method) {
                $code                 = $method->getCode();
                $available[$code]     = (string) $method->getTitle();
                $methodObjects[$code] = $method;
            }

            // Re-add active methods excluded by quote constraints (e.g. offline
            // methods self-exclude for virtual carts). alwaysExcludedCodes (di.xml)
            // blocks amount-gated methods like `free` that getActiveList() returns
            // regardless of cart total.
            foreach ($this->paymentMethodList->getActiveList($storeId) as $dto) {
                $code  = $dto->getCode();
                $title = (string) $dto->getTitle();
                if (!isset($available[$code]) && $title !== ''
                    && !in_array($code, $this->alwaysExcludedCodes, true)) {
                    $available[$code] = $title;
                }
            }

            $this->logger->info(sprintf(
                'INSEAD payment methods | Store: %s | Virtual: %s | Available: [%s]',
                $this->storeManager->getStore($storeId)->getCode(),
                $quote->isVirtual() ? 'Yes' : 'No',
                implode(', ', array_keys($available))
            ));
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout payment methods: ' . $e->getMessage());
        }

        $others = [];
        foreach ($available as $code => $title) {
            // PayPal redirect methods — token handshake incompatible with flat checkout.
            if (in_array($code, $this->paypalRedirectMethods, true)) {
                continue;
            }

            // Payment-Element methods (Stripe): detected by MethodInterface class
            // namespace from di.xml — upgrade-safe if the gateway renames codes.
            $obj = $methodObjects[$code] ?? null;
            if ($obj !== null) {
                $className = get_class($obj);
                foreach ($this->paymentElementNamespaces as $ns) {
                    if (strpos($className, $ns) === 0) {
                        continue 2;
                    }
                }
            } elseif (str_starts_with($code, 'stripe_')) {
                // Re-added entries have no object; secondary code-prefix guard.
                continue;
            }

            // Drop-in sub-methods (Braintree): only top-level codes get a UI slot.
            // All Braintree methods are Magento\Payment\Model\Method\Adapter virtual
            // types (no shared base class), so model config value is used for detection.
            if (!in_array($code, $this->dropinTopLevelCodes, true)) {
                $modelConfig = (string) $this->scopeConfig->getValue(
                    'payment/' . $code . '/model',
                    ScopeInterface::SCOPE_STORE,
                    $storeId ?: null
                );
                foreach ($this->dropinModelPrefixes as $prefix) {
                    if (str_starts_with($modelConfig, $prefix)) {
                        continue 2;
                    }
                }
            }

            $others[] = ['code' => $code, 'title' => $title];
        }

        // Card/bank slots use $methodObjects (quote-validated) not $available so
        // Stripe only appears when it passed full isAvailable() credential checks.
        return [
            'card'   => isset($methodObjects[$this->stripeCard])
                ? ['code' => $this->stripeCard, 'title' => $available[$this->stripeCard]]
                : null,
            'bank'   => isset($methodObjects[$this->stripeBank])
                ? ['code' => $this->stripeBank, 'title' => $available[$this->stripeBank]]
                : null,
            'others' => $others,
        ];
    }
}
