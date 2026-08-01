<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * Where a material lot's receipt rate came from.
 *
 * A NULL rate_source (no case here) means UNKNOWN — the honest answer for
 * opening-stock lots, which have no GRN and therefore no purchase rate
 * anywhere in this system. Unknown is not zero and must not be shown as
 * zero.
 */
enum MaterialLotRateSource: string
{
    /** goods_receipt_note_lines.unit_cost — the rate the receipt used. */
    case Grn = 'grn';

    /**
     * purchase_order_lines.unit_price — reached past the GRN to the order,
     * because the receipt line carried no usable rate of its own.
     */
    case Po = 'po';

    /** A rate a person supplied directly when registering the lot. */
    case Manual = 'manual';
}
