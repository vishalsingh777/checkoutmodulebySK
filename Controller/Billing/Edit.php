<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Controller\Billing;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * "Edit Billing Information" — clears the insead_billing_confirmed flag on
 * the current quote and sends the browser back to /checkout, which
 * Observer\AddCustomCheckoutLayoutHandle then routes back to the custom
 * Billing Information step (still prefilled with whatever was entered
 * before, since none of that quote data is cleared — only the confirmation
 * flag). Linked from Block\PaymentPageExtras, which is added to native
 * checkout_index_index.xml without removing checkout.root — see that
 * block/layout for where this link actually appears.
 */
class Edit implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $quote->setData('insead_billing_confirmed', 0);
                // CartRepositoryInterface, not $quote->save() — see
                // Controller\Billing\Save for why the legacy save is unsafe
                // here (doesn't reliably cascade nested address changes).
                $this->cartRepository->save($quote);
            }
        } catch (\Throwable $e) {
            $this->logger->error('INSEAD billing edit reset: ' . $e->getMessage());
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout');
        return $resultRedirect;
    }
}

