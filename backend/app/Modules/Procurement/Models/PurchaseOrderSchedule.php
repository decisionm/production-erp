<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item/due-date delivery allocation of a purchase-order line — the
 * mirror of Tally's ORDERNO/ORDERDUEDATE allocation rows. A GRN arrival
 * consumes schedules oldest-due-first by default; quantity_received tracks
 * how much of this window has physically arrived.
 */
class PurchaseOrderSchedule extends Model
{
    protected $fillable = [
        'purchase_order_line_id', 'due_date', 'quantity', 'quantity_received', 'tally_reference',
    ];

    protected $casts = [
        'due_date' => 'date',
        'quantity' => 'decimal:4',
        'quantity_received' => 'decimal:4',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** Open quantity still expected against this due date. */
    public function remaining(): string
    {
        return bcsub((string) $this->quantity, (string) $this->quantity_received, 4);
    }
}
