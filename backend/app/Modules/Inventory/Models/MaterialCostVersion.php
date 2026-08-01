<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialCostVersionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded statement of what a material lot cost per kg.
 *
 * APPEND-ONLY. Nothing in this codebase updates or deletes a row here, and
 * no route exposes either verb. A revision is a new row pointing back at
 * the one it replaces (supersedes_id), which is why there is no updated_at
 * column — see UPDATED_AT below.
 *
 * A version is inert with respect to stock: writing one moves no quantity,
 * changes no balance, re-prices no completed batch and reaches no Tally
 * voucher. It is a fact on the record that later readers may consult.
 */
#[Fillable(['material_lot_id', 'rate_per_kg', 'kind', 'note', 'created_by', 'supersedes_id'])]
class MaterialCostVersion extends Model
{
    /**
     * No updated_at column exists, so Eloquent must not try to write one.
     * This is the append-only rule expressed in code: there is no "last
     * modified" for a row that never changes.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'rate_per_kg' => 'decimal:4',
            'kind' => MaterialCostVersionKind::class,
            'created_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'material_lot_id');
    }

    /** The version this one replaced, or null for the first in the chain. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
