<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Gst;

use Insead\CustomCheckout\Model\Gst\CustomerGroupResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets the PLACED ORDER's own `customer_group_id` to whichever of the 4
 * Singapore GST groups (B2C/B2C_GST/B2B/B2B_EXEMPT) actually governed its
 * tax calculation — permanently, on the order row only. Mirrors
 * Observer\Vat\SetOrderCustomerGroup exactly, for the GST mechanism instead
 * of the VAT one; see that class's docblock for the full rationale (does not
 * touch the customer's account group, does not change the quote-scoped
 * swap/restore behavior used during active checkout).
 *
 * CustomerGroupResolver::resolve() takes the Quote directly (not an address),
 * so this one is simpler than the VAT version — no address/type juggling.
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

        // CustomerGroupResolver::resolve() doesn't check this flag itself —
        // that gate normally lives in Observer\Gst\SetQuoteCustomerGroup —
        // so it must be checked here too, or this would incorrectly apply
        // GST groups on stores that don't use this mechanism (e.g. icp_fra),
        // since the config paths still resolve to a value via their defaults.
        if (!$this->scopeConfig->isSetFlag(
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
            $this->logger->error('INSEAD GST order customer group set: ' . $e->getMessage());
        }
    }
}
