<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;

/**
 * Order summary (cart review) rendered alongside the custom checkout form.
 */
class Summary extends Template
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getQuote(): ?Quote
    {
        try {
            return $this->checkoutSession->getQuote();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int,array{name:string,qty:float,image:string,rowTotal:string}>
     */
    public function getItems(): array
    {
        $quote = $this->getQuote();
        if (!$quote) {
            return [];
        }
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $items[] = [
                'name'     => (string) $item->getName(),
                'qty'      => (float) $item->getQty(),
                'image'    => $this->imageHelper->init($product, 'product_thumbnail_image')->getUrl(),
                'rowTotal' => $this->formatPrice((float) $item->getRowTotalInclTax() ?: (float) $item->getRowTotal()),
            ];
        }
        return $items;
    }

    public function getItemCount(): int
    {
        $quote = $this->getQuote();
        return $quote ? (int) $quote->getItemsQty() : 0;
    }

    /**
     * @return array<int,array{label:string,value:string,strong:bool}>
     */
    public function getTotals(): array
    {
        $quote = $this->getQuote();
        if (!$quote) {
            return [];
        }
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        $rows = [];
        $rows[] = ['label' => (string) __('Subtotal'), 'value' => $this->formatPrice((float) $quote->getSubtotal()), 'strong' => false];

        $discount = (float) $address->getDiscountAmount();
        if ($discount != 0.0) {
            $rows[] = ['label' => (string) __('Discount'), 'value' => $this->formatPrice($discount), 'strong' => false];
        }

        $shipping = (float) $address->getShippingAmount();
        if (!$quote->isVirtual() && $shipping > 0) {
            $rows[] = ['label' => (string) __('Shipping'), 'value' => $this->formatPrice($shipping), 'strong' => false];
        }

        $tax = (float) $address->getTaxAmount();
        if ($tax > 0) {
            $rows[] = ['label' => (string) __('Tax'), 'value' => $this->formatPrice($tax), 'strong' => false];
        }

        $rows[] = ['label' => (string) __('Order total'), 'value' => $this->formatPrice((float) $quote->getGrandTotal()), 'strong' => true];
        return $rows;
    }

    public function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format($amount, false);
    }
}
