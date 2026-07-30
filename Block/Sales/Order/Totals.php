<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block\Sales\Order;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;

/**
 * Adds the "Singapore GST" row to the order Totals block (frontend "My
 * Orders" and admin order view both use Magento\Sales\Block\Order\Totals /
 * Magento\Sales\Block\Adminhtml\Order\Totals under the same "order_totals"
 * block name — see view/frontend and view/adminhtml layout/sales_order_view.xml).
 * Mirrors Magento\Weee\Block\Sales\Order\Totals — the same mechanism core
 * uses to add its own extra total row to this same block.
 *
 * Reads insead_gst_amount off the order (copied from the quote by
 * Observer\Gst\CopyGstAmountToOrder — see Model\Total\Quote\Gst for what
 * this amount is and why it isn't a Tax Rule).
 */
class Totals extends Template
{
    public function getSource()
    {
        return $this->getParentBlock()->getSource();
    }

    public function initTotals(): self
    {
        $amount = (float) $this->getSource()->getData('insead_gst_amount');
        if ($amount <= 0) {
            return $this;
        }

        $total = new DataObject([
            'code' => 'insead_gst',
            'label' => __('Singapore GST'),
            'value' => $amount,
        ]);

        $totals = $this->getParentBlock()->getTotals();
        if (isset($totals['grand_total'])) {
            $this->getParentBlock()->addTotalBefore($total, 'grand_total');
        } else {
            $this->getParentBlock()->addTotal($total);
        }

        return $this;
    }
}
