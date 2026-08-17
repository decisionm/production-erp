<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One material on one store issue: how much left the store, how much has
 * come back, and which request line it answers.
 *
 * quantity_requested is a SNAPSHOT of what the request asked for at the
 * moment of issue, so "still outstanding on that request" is arithmetic
 * over these rows alone — no join to another module's table, and no drift
 * if the request is later amended.
 */
#[Fillable([
    'store_issue_id', 'material_request_line_id', 'quantity_requested', 'item_id',
    'from_warehouse_id', 'to_warehouse_id', 'quantity_issued', 'quantity_returned',
    'uom', 'notes',
])]
class StoreIssueLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'quantity_issued' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
        ];
    }

    public function storeIssue(): BelongsTo
    {
        return $this->belongsTo(StoreIssue::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function bagScans(): HasMany
    {
        return $this->hasMany(StoreIssueBagScan::class);
    }

    /** What of THIS line is still standing with production, in kg/Nos. */
    public function quantityOutstanding(): string
    {
        return bcsub((string) $this->quantity_issued, (string) $this->quantity_returned, 4);
    }

    /**
     * What the REQUEST line still wants — its snapshot quantity minus
     * everything issued against it, across every issue, not just this one.
     * Null when the line answers no request.
     *
     * Across every issue is the whole point: a request fulfilled 200 now and
     * 300 next week is one request with nothing remaining, and a figure read
     * off a single issue would say 300 are still owed. Cancelled issues do
     * not count — their material went back to the store.
     *
     * Computed, never stored: a remaining column would be one more number to
     * keep in step by hand, and the two would eventually disagree.
     */
    public function quantityRemainingOnRequest(): ?string
    {
        if ($this->material_request_line_id === null || $this->quantity_requested === null) {
            return null;
        }

        $issued = static::query()
            ->where('material_request_line_id', $this->material_request_line_id)
            ->whereHas('storeIssue', fn ($query) => $query->where('status', '!=', StoreIssueStatus::Cancelled->value))
            ->get()
            ->reduce(fn (string $carry, self $line) => bcadd($carry, (string) $line->quantity_issued, 4), '0.0000');

        $left = bcsub((string) $this->quantity_requested, $issued, 4);

        return bccomp($left, '0', 4) === -1 ? '0.0000' : $left;
    }
}
