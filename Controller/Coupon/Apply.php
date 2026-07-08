<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Coupon;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Applies (or, with an empty code, removes) a native Magento Sales Rule
 * coupon code on the current checkout-session quote.
 *
 * Deliberately does NOT go through Magento\Quote\Api\CouponManagementInterface
 * — a pre-existing, unrelated plugin (Tealium\Tags\Model\Plugin\
 * CouponManagementPlugin::aroundSet(), registered on Magento\Quote\Model\
 * CouponManagement) calls $proceed() with no arguments, which breaks that
 * service for every caller app-wide (native cart coupon box included).
 * Instead this replicates CouponManagement::set()/remove()'s own logic
 * directly — setCouponCode() + collectTotals() + save, then verify the code
 * actually stuck — which is the same pattern Ewave_InseadIntegration's
 * PopulateQuote already uses successfully, and sidesteps the broken plugin
 * entirely without touching that other module.
 */
class Apply implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $couponCode = trim((string) $this->request->getParam('coupon_code', ''));

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || !$quote->hasItems()) {
                return $result->setData(['success' => false, 'message' => __('Your cart is empty.')]);
            }

            $quote->setCouponCode($couponCode);
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $this->cartRepository->save($quote->collectTotals());

            if ((string) $quote->getCouponCode() !== $couponCode) {
                throw new NoSuchEntityException(
                    __("The coupon code isn't valid. Verify the code and try again.")
                );
            }

            return $result->setData([
                'success' => true,
                'message' => $couponCode !== ''
                    ? __('Coupon code applied successfully.')
                    : __('The coupon code was removed.'),
                'totals'  => $this->formatTotals($quote),
                // The Sales Rule discount collector allocates the coupon discount
                // across line items (quote_item.discount_amount), so the "OFFERING"
                // % shown per item needs refreshing too, not just the totals block.
                'items'   => $this->formatItems($quote),
            ]);
        } catch (LocalizedException $e) {
            // Invalid/expired/usage-limited codes surface here with Magento's
            // own message (e.g. "The coupon code isn't valid...").
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout coupon apply: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => __('Unable to apply the coupon code.')]);
        }
    }

    /**
     * Mirrors Block\Checkout::getSummary()'s per-item formatting (fee /
     * offering % / final fee), so the "OFFERING" column reflects the coupon
     * discount the same way it already reflects any other cart price rule.
     *
     * @return array<int,array{name:string,qty:float,fee:string,offering:string,finalFee:string}>
     */
    private function formatItems(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $fee = (float) $item->getPrice();
            $final = (float) ($item->getRowTotal() ?: ($fee * (float) $item->getQty()));
            $offering = $fee > 0 && (float) $item->getDiscountAmount() > 0
                ? round(((float) $item->getDiscountAmount() / ($fee * (float) $item->getQty())) * 100)
                : 0;
            $items[] = [
                'name'     => (string) $item->getName(),
                'qty'      => (float) $item->getQty(),
                'fee'      => $this->priceCurrency->format($fee, false),
                'offering' => (int) $offering . '%',
                'finalFee' => $this->priceCurrency->format($final, false),
            ];
        }
        return $items;
    }

    /**
     * @return array{subtotal:string,discount:string,tax:string,grand:string,grandRaw:string,couponCode:string}
     */
    private function formatTotals(Quote $quote): array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        return [
            'subtotal'   => $this->priceCurrency->format((float) $quote->getSubtotal(), false),
            'discount'   => $this->priceCurrency->format(abs((float) $address->getDiscountAmount()), false),
            'tax'        => $this->priceCurrency->format((float) $address->getTaxAmount(), false),
            'grand'      => $this->priceCurrency->format((float) $quote->getGrandTotal(), false),
            'grandRaw'   => number_format((float) $quote->getGrandTotal(), 2, '.', ''),
            'couponCode' => (string) $quote->getCouponCode(),
        ];
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
