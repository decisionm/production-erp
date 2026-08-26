<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PRODUCTION REQUEST — the shortfall on a sales order line, handed to the
 * floor as a prioritised worklist item.
 *
 * PAPERWORK ONLY. It creates no batch, starts no batch and cancels no batch;
 * it carries no FK into shift_production_entries and nothing in Production's
 * batch lifecycle reads it (invariant 2). People start batches. `start()`
 * here means somebody said they had picked the job up.
 *
 * NO ETA COLUMN (S11). The date the floor is asked for is computed on read
 * by FulfilmentPlanningService and never stored, because a stored one is
 * already wrong the moment somebody reorders the queue.
 *
 * FC-06: no rate, no amount, no vendor.
 */
#[Fillable([
    'sales_order_line_id', 'item_id', 'quantity', 'priority',
    'status', 'requested_by', 'cancelled_reason',
])]
class ProductionRequest extends Model
{
    /**
     * {start, cancel, reorder} as ProductionRequestService::abilities
     * computed them — stamped on every row the service hands back, printed
     * by the resource, and enforced by the actions themselves. Null means
     * "not decorated", never "nothing allowed".
     *
     * @var array{start: bool, cancel: bool, reorder: bool}|null
     */
    public ?array $can = null;

    protected function casts(): array
    {
        return [
            'status' => ProductionRequestStatus::class,
            'quantity' => 'decimal:4',
            'priority' => 'integer',
        ];
    }

    /** "PR-{id}" — the number the store and the floor quote at each other. */
    public function documentNumber(): string
    {
        return "PR-{$this->id}";
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Still owed — queued or in progress. The ONE definition the
     * one-open-request-per-line rule, the queue and the planning walk all
     * use.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ProductionRequestStatus::Queued,
            ProductionRequestStatus::InProgress,
        ]);
    }
}
