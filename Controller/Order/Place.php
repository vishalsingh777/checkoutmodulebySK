<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Order;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\UrlInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartManagementInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal payment step: assigns a shipping method (if physical) and the chosen
 * payment method to the quote, then places the order and returns the success
 * redirect. Replaces the default Magento payment step that was removed from the
 * checkout layout.
 */
class Place implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartManagementInterface $cartManagement,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        try {
            $paymentMethod = (string) $this->request->getParam('payment_method');
            if ($paymentMethod === '') {
                return $result->setData(['success' => false, 'message' => __('Please select a payment method.')]);
            }
            // Stripe Payment Element id (pm_xxx) created client-side; the Stripe
            // method's assignData() maps it to additional_information['token'].
            $paymentMethodId = (string) $this->request->getParam('payment_method_id');

            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId() || !$quote->hasItems()) {
                return $result->setData(['success' => false, 'message' => __('Your cart is empty.')]);
            }

            // Physical products: mirror billing to shipping and pick a rate.
            if (!$quote->isVirtual()) {
                $shipping = $quote->getShippingAddress();
                $shipping->addData($quote->getBillingAddress()->getData());
                $shipping->setSameAsBilling(1)
                    ->setCollectShippingRates(true)
                    ->collectShippingRates();

                $method = $this->resolveShippingMethod($shipping);
                if ($method === null) {
                    return $result->setData([
                        'success' => false,
                        'message' => __('No shipping method is available for this address.'),
                    ]);
                }
                $shipping->setShippingMethod($method);
            }

            $paymentData = ['method' => $paymentMethod];
            if ($paymentMethodId !== '') {
                // Routed through the gateway's assignData() → additional_information['token'].
                $paymentData['additional_data'] = ['payment_method' => $paymentMethodId];
            }
            $quote->getPayment()->importData($paymentData);
            $quote->collectTotals()->save();

            $orderId = $this->cartManagement->placeOrder($quote->getId());

            $this->checkoutSession->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($orderId);

            return $result->setData([
                'success'  => true,
                'redirect' => $this->url->getUrl('checkout/onepage/success'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD checkout place order: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __($e->getMessage() ?: 'Unable to place the order.'),
            ]);
        }
    }

    /**
     * First available shipping rate code (prefers flatrate / freeshipping).
     */
    private function resolveShippingMethod($shippingAddress): ?string
    {
        $rates = $shippingAddress->getAllShippingRates();
        $codes = [];
        foreach ($rates as $rate) {
            $codes[] = $rate->getCode();
        }
        foreach (['flatrate_flatrate', 'freeshipping_freeshipping'] as $preferred) {
            if (in_array($preferred, $codes, true)) {
                return $preferred;
            }
        }
        return $codes[0] ?? null;
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
