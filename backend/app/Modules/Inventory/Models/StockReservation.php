<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A HOLD on finished goods the factory already has, for one customer's
 * order line.
 *
 * It moves NO stock, ever (invariant 1). The stock_balances row is
 * untouched by reserving, releasing and re-pointing; only a Delivery moves
 * stock, and when it does it CONSUMES the hold rather than the hold causing
 * the movement.
 *
 * NO SOFT DELETES and NO ACTIVITY LOG: a transactional document, not a
 * configuration master. A hold that is given up keeps its row, its reason
 * and its author.
 *
 * `released_reason` / `released_by` describe the MOST RECENT give-up, not
 * the row's whole history. A hold can be given up more than once — 30
 * pieces re-pointed today, the remaining 70 released tomorrow — and there
 * is deliberately no per-event log behind it: released_quantity says how
 * much has been given up in total, and the reason says why the last of it
 * was. A row that is still `active` with a reason on it is therefore
 * normal, and means exactly that: part of it has been given up.
 *
 * FC-06: no rate, no amount, no supplier anywhere on this shape.
 */
#[Fillable([
    'item_id', 'warehouse_id', 'sales_order_line_id',
    'quantity', 'consumed_quantity', 'released_quantity',
    'status', 'released_reason', 'created_by', 'released_by',
])]
class StockReservation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StockReservationStatus::class,
            'quantity' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
            'released_quantity' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** Holds that are still keeping stock away from everyone else. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StockReservationStatus::Active);
    }

    /**
     * WHAT THIS HOLD IS STILL HOLDING — quantity less what already left and
     * what was already given up.
     *
     * THE ONE FIGURE both the availability read AND the coverage test sum
     * (markProducedIfCovered adds it to quantity_delivered, which already
     * carries every consumed piece — counting a hold's consumed part again
     * there would double-count a delivery against its own hold).
     */
    public function outstandingQuantity(): string
    {
        return bcsub(
            bcsub((string) $this->quantity, (string) $this->consumed_quantity, 4),
            (string) $this->released_quantity,
            4,
        );
    }
}
