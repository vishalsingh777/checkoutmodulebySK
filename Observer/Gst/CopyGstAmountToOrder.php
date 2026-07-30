<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Gst;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Copies the GST amount Model\Total\Quote\Gst computed on the quote onto the
 * placed order (sales_order.insead_gst_amount / insead_base_gst_amount), so
 * it's queryable there for finance/reporting. Magento's quote-to-order
 * conversion only carries over well-known total fields (subtotal,
 * tax_amount, shipping_amount, grand_total, ...) — a new custom total's
 * amount isn't copied automatically.
 */
class CopyGstAmountToOrder implements ObserverInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $quote = $observer->getEvent()->getQuote();
        if (!$order || !$order->getId() || !$quote) {
            return;
        }

        $amount = (float) $quote->getData('insead_gst_amount');
        if ($amount <= 0.0) {
            return;
        }

        try {
            $order->setData('insead_gst_amount', $amount);
            $order->setData('insead_base_gst_amount', (float) $quote->getData('insead_base_gst_amount'));
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            // Non-fatal: the order itself is already placed correctly (the
            // GST amount is already folded into its grand_total via
            // Model\Total\Quote\Gst) — only the separate reporting column
            // would be missing.
            $this->logger->error('INSEAD GST amount copy to order: ' . $e->getMessage());
        }
    }
}
