<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Gst;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Restores whatever customer group SetQuoteCustomerGroup (GST) swapped away
 * from, once tax totals have finished collecting — so nothing downstream
 * (invoice export, order emails, account pages) ever sees the temporary
 * swap. Mirrors Observer\Vat\RestoreQuoteCustomerGroup; uses its own stash
 * key (`insead_prev_gst_customer_group_id`) and processed flag so the two
 * mechanisms never interfere with each other even if both observers were
 * ever registered on the same quote.
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
        if ($address && $address->hasData('insead_prev_gst_customer_group_id')) {
            $quote->setCustomerGroupId($address->getData('insead_prev_gst_customer_group_id'));
            $address->unsetData('insead_prev_gst_customer_group_id');
        }

        $quote->unsetData(SetQuoteCustomerGroup::PROCESSED_FLAG);
    }
}
