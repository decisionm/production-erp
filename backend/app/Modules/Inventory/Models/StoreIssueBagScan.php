<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bag, scanned at the handover: which bag, from which lot, how many
 * kilograms, issued by whom, received by whom, when, against which request.
 *
 * THE TRACE STOPS HERE. This row is the last link the ERP will ever claim
 * about a bag — it says the bag went to production, never that a particular
 * batch used it (FC-01; batch consumption stays calculated). There is
 * likewise no machine and no area on it: resin enters through one common
 * piped loading point (DEC-20260807-006).
 */
#[Fillable([
    'store_issue_id', 'store_issue_line_id', 'material_request_line_id',
    'material_bag_id', 'material_lot_id', 'quantity_kg', 'issued_by',
    'received_by', 'scanned_at', 'notes',
])]
class StoreIssueBagScan extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:4',
            'scanned_at' => 'datetime',
        ];
    }

    public function storeIssue(): BelongsTo
    {
        return $this->belongsTo(StoreIssue::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StoreIssueLine::class, 'store_issue_line_id');
    }

    public function bag(): BelongsTo
    {
        return $this->belongsTo(MaterialBag::class, 'material_bag_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'material_lot_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
