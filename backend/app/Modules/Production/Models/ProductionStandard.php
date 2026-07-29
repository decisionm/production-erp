<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One product-level standard variant: cavities + weight + cycle time, with
 * the packaging options that standard can be packed in.
 */
#[Fillable([
    'item_id', 'source_product_name', 'cavities', 'unit_weight_grams',
    'cycle_time', 'cycle_time_raw', 'status', 'unresolved_reason',
    'source', 'source_reference', 'confirmation_status', 'notes',
    'approved_by', 'approved_at', 'created_by',
])]
class ProductionStandard extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'cavities' => 'integer',
            'unit_weight_grams' => 'decimal:4',
            'cycle_time' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ProductionStandardPackaging::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** A short human label distinguishing sibling variants of one product. */
    public function variantLabel(): string
    {
        $parts = array_filter([
            $this->cavities !== null ? "{$this->cavities} cav" : null,
            $this->unit_weight_grams !== null ? rtrim(rtrim((string) $this->unit_weight_grams, '0'), '.').' g' : null,
            $this->cycle_time !== null ? rtrim(rtrim((string) $this->cycle_time, '0'), '.').' s' : null,
        ]);

        return $parts === [] ? 'standard' : implode(' · ', $parts);
    }
}
