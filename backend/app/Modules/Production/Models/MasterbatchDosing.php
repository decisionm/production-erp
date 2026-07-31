<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How many grams of one masterbatch go into one bottle.
 *
 * Master data with provenance, not a recipe: the only thing it produces is a
 * PREFILL on the completion form. What the supervisor weighed and submitted
 * is what is stored, consumed and posted — see
 * MasterbatchDosingService's docblock for why that separation is absolute.
 *
 * `product_item_id` null = applies to every product using this masterbatch.
 */
#[Fillable([
    'masterbatch_item_id', 'product_item_id', 'grams_per_bottle',
    'note', 'set_by', 'set_at',
])]
class MasterbatchDosing extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            // decimal:4, so the value reaches bcmath as the exact string
            // '0.2500' rather than a float that has already lost precision.
            'grams_per_bottle' => 'decimal:4',
            'set_at' => 'datetime',
        ];
    }

    public function masterbatchItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'masterbatch_item_id')->withTrashed();
    }

    public function productItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'product_item_id')->withTrashed();
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** 'product' when this row was set for one bottle, 'factory' when it applies to all. */
    public function scope(): string
    {
        return $this->product_item_id === null ? 'factory' : 'product';
    }
}
