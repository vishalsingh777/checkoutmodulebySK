<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Insead\CustomCheckout\Model\Vat\CustomerGroupResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets the PLACED ORDER's own `customer_group_id` to whichever of the 4
 * VAT/tax groups (B2C/B2B_REGISTERED/B2B_NON_REGISTERED/B2B_TAX_EXEMPT)
 * actually governed its tax calculation — permanently, on the order row
 * only. Does NOT touch the customer's account group (customer_entity) and
 * does NOT change anything about Observer\Vat\SetQuoteCustomerGroup /
 * RestoreQuoteCustomerGroup, which keep swapping the QUOTE's group
 * temporarily during active checkout (still needed so mid-session totals
 * collections, e.g. an intermediate page reload, aren't affected). This
 * only runs once, after the order already exists, to make the *order
 * itself* show which group actually computed its tax instead of the
 * customer's real account group ("General," etc.).
 *
 * Hooks `checkout_submit_all_after` — fires for every order placement
 * regardless of payment method or code path (native checkout, admin,
 * REST/GraphQL).
 */
class SetOrderCustomerGroup implements ObserverInterface
{
    public function __construct(
        private readonly CustomerGroupResolver $groupResolver,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $quote = $observer->getEvent()->getQuote();
        if (!$order || !$order->getId() || !$quote || !$quote->getData('insead_billing_confirmed')) {
            return;
        }

        // Same store-exclusivity rule as SetQuoteCustomerGroup: never act on
        // a store using the GST mechanism instead.
        if ($this->scopeConfig->isSetFlag(
            'insead_checkout/gst_group/enabled',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        )) {
            return;
        }

        $groupId = $this->groupResolver->resolve($quote);

        if ($groupId === null || $groupId === (int) $order->getCustomerGroupId()) {
            return;
        }

        try {
            $order->setCustomerGroupId($groupId);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            // Non-fatal: the order itself is already placed correctly and
            // its tax was already computed correctly during checkout — only
            // this order-level display field would be missing.
            $this->logger->error('INSEAD VAT order customer group set: ' . $e->getMessage());
        }
    }
}
