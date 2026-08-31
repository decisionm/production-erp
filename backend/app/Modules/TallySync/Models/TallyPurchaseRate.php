<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE LINE OF ONE PURCHASE VOUCHER, as the factory's Tally already holds it.
 *
 * Read-only in every direction that matters: the agent writes these rows from
 * a Day Book export, nothing in the ERP edits one, and no voucher is ever
 * posted from them. They exist so a person raising a purchase order can see
 * what this vendor last charged for this item instead of remembering it.
 *
 * WHY BOTH KINDS ARE KEPT. A Purchase Order rate is what was AGREED; a
 * Purchase invoice rate is what was BILLED. They differ, and which one should
 * lead a new order is Accounts' judgement, not this table's — so both are
 * stored, both are shown, and the suggestion names which one it came from.
 */
#[Fillable([
    'voucher_guid', 'line_index', 'voucher_type', 'voucher_number', 'voucher_reference', 'voucher_date',
    'party_ledger_name', 'party_gstin',
    'stock_item_name', 'tally_stock_item_guid',
    'rate_value', 'rate_unit', 'quantity', 'quantity_unit', 'amount',
    'cgst_rate', 'sgst_rate', 'igst_rate', 'cess_rate', 'hsn_code', 'purchase_ledger_name',
    'tally_company', 'tally_synced_at',
])]
class TallyPurchaseRate extends Model
{
    /** A Tally Purchase Order voucher — what was agreed. */
    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    /** A Tally Purchase voucher (the supplier's invoice) — what was billed. */
    public const TYPE_PURCHASE_INVOICE = 'purchase_invoice';

    /** @var list<string> */
    public const TYPES = [self::TYPE_PURCHASE_ORDER, self::TYPE_PURCHASE_INVOICE];

    /**
     * The money and quantity columns are cast to DECIMAL STRINGS, not left to
     * the driver.
     *
     * The suite runs on sqlite as its fast leg and MySQL as its parity leg
     * (the live instance is MySQL), and the two hand a `decimal` column back
     * differently — sqlite as a PHP number, MySQL as a string. A rate that is
     * `674` on one and `'674.000000'` on the other is a rate whose precision
     * depends on where it was read, and it reaches the purchase-order form
     * either way. Casting here fixes one answer for both.
     */
    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'tally_synced_at' => 'datetime',
            'line_index' => 'integer',
            'rate_value' => 'decimal:6',
            'quantity' => 'decimal:4',
            'amount' => 'decimal:4',
            'cgst_rate' => 'decimal:4',
            'sgst_rate' => 'decimal:4',
            'igst_rate' => 'decimal:4',
            'cess_rate' => 'decimal:4',
        ];
    }
}
