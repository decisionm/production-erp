<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material's common-input resin pool: priced kg, their weighted average
 * rate, and the unpriced kg deliberately kept out of that average.
 *
 * Written only by ResinPoolService, always under that service's row lock —
 * see it and the table's migration for the whole rationale.
 */
#[Fillable(['item_id', 'quantity_kg', 'avg_rate_per_kg', 'unpriced_kg'])]
class ResinPoolBalance extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:4',
            'avg_rate_per_kg' => 'decimal:4',
            'unpriced_kg' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
