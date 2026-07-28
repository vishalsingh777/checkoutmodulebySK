<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Restores whatever customer group SetQuoteCustomerGroup swapped away from,
 * once tax totals have finished collecting — so nothing downstream (invoice
 * export, order confirmation, account pages) ever sees the temporary swap.
 */
class RestoreQuoteCustomerGroup implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote || !$quote->hasData(SetQuoteCustomerGroup::PROCESSED_FLAG)) {
            return;
        }

        $address = $quote->getBillingAddress();
        if ($address && $address->hasData('insead_prev_customer_group_id')) {
            $quote->setCustomerGroupId($address->getData('insead_prev_customer_group_id'));
            $address->unsetData('insead_prev_customer_group_id');
        }

        $quote->unsetData(SetQuoteCustomerGroup::PROCESSED_FLAG);
    }
}
