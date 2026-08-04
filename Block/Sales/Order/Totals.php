<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block\Sales\Order;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;

/**
 * Adds a "Singapore GST" row to the order Totals block (frontend "My
 * Orders" and admin order view both use Magento\Sales\Block\Order\Totals /
 * Magento\Sales\Block\Adminhtml\Order\Totals under the same "order_totals"
 * block name — see view/frontend and view/adminhtml layout/sales_order_view.xml).
 * Mirrors Magento\Weee\Block\Sales\Order\Totals — the same mechanism core
 * uses to add its own extra total row to this same block.
 *
 * DORMANT as of the move to native Tax Rules for GST (see
 * Model\Gst\CustomerGroupResolver): `insead_gst_amount` is no longer written
 * by anything (the total that used to populate it, Model\Total\Quote\Gst,
 * was retired), so initTotals() below will always see 0 and add nothing.
 * GST charged via a Tax Rule shows up folded into the standard "Tax" row
 * instead of this separate line — that is an intentional, visible change
 * from the old behavior, flagged for confirmation rather than silently
 * removing this block. Safe to delete this class + its layout XML wiring
 * once that's confirmed acceptable.
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
