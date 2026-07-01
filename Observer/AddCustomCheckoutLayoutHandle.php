<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Gates the INSEAD custom checkout takeover behind
 * Stores > Configuration > INSEAD > Checkout > INSEAD Custom Checkout >
 * Enable Custom Checkout Step for the current store view.
 *
 * checkout_index_index.xml carries no override of its own — the
 * remove-native-checkout / add-custom-block markup lives in
 * insead_customcheckout_enabled.xml, a layout update only applied when this
 * observer adds its handle. So a store view/website with the setting off
 * gets the untouched native Magento checkout, regardless of what any other
 * website/store view (with its own theme/design) has configured.
 */
class AddCustomCheckoutLayoutHandle implements ObserverInterface
{
    private const XML_PATH_ENABLED = 'insead_checkout/insead_customcheckout/enabled';
    private const HANDLE = 'insead_customcheckout_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($observer->getEvent()->getFullActionName() !== 'checkout_index_index') {
            return;
        }
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return;
        }
        $observer->getEvent()->getLayout()->getUpdate()->addHandle(self::HANDLE);
    }
}
