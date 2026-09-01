<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Exceptions\StockLedgerAppendOnlyException;
use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the stock ledger. APPEND-ONLY: a movement is a fact about
 * what happened, and stock_balances is derived from the sum of these facts
 * (inventory:check-ledger proves it). A wrong movement is answered by a NEW
 * movement that reverses it — see ShiftProductionEntryService's amendment
 * and QC-return paths — never by editing or deleting the original. The
 * booted() guard below refuses both through Eloquent.
 *
 * What the guard does NOT cover, on purpose: query-builder bulk writes
 * (StockMovement::query()->...->delete()) fire no model events. Exactly one
 * place uses that — production:reset-test-data, a dry-run-first operator command
 * that clears a rehearsal and RECOMPUTES the balances from what survives —
 * and it is meant to. Nothing in a request path may.
 */
#[Fillable([
    'item_id', 'warehouse_id', 'batch_id', 'serial_number_id', 'type', 'purpose', 'quality_state',
    'quantity', 'unit_cost',
    'reference', 'transfer_group', 'movement_date', 'notes', 'created_by',
])]
class StockMovement extends Model
{
    protected static function booted(): void
    {
        static::updating(function (StockMovement $movement): void {
            throw StockLedgerAppendOnlyException::forUpdate($movement->getKey());
        });

        static::deleting(function (StockMovement $movement): void {
            throw StockLedgerAppendOnlyException::forDelete($movement->getKey());
        });
    }

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'purpose' => StockMovementPurpose::class,
            // Nullable on purpose and NOT defaulted here: a receipt or a
            // consumption is not being asked this question, and casting a
            // null to `good` would answer for them. Only the return path
            // reads it, through ReturnedQualityState::fromNullable().
            'quality_state' => ReturnedQualityState::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'movement_date' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(SerialNumber::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
