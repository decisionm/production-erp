<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE SALES-ORDER LINE THE FACTORY HAS STILL TO SHIP, as Tally holds it.
 *
 * The client's own purchase order, in other words: `order_reference` is the
 * same fact `sales_orders.customer_po_reference` records for an order raised in
 * the ERP. Written only by TallyReceivableSyncService, from a Sales Order
 * Outstanding export.
 *
 * QUANTITY AND VALUE ARE BOTH OPTIONAL AND NEITHER IS DERIVED FROM THE OTHER.
 * Tally states what it states; a pending value computed here from a quantity
 * and a rate would be this table inventing a number the factory never wrote
 * down. A line with neither is still kept — it is a real pending order, and
 * the page counts it rather than dropping it silently.
 */
#[Fillable([
    'party_ledger_name', 'party_ledger_guid',
    'order_reference', 'order_date', 'due_date', 'stock_item_name',
    'pending_quantity', 'quantity_unit', 'pending_amount',
    'as_of', 'tally_company', 'tally_synced_at',
])]
class TallyPendingSalesOrder extends Model
{
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'due_date' => 'date',
            'as_of' => 'date',
            'tally_synced_at' => 'datetime',
            'pending_quantity' => 'decimal:4',
            'pending_amount' => 'decimal:4',
        ];
    }
}
