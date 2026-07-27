<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sku', 'name', 'description', 'uom', 'hsn_sac_code', 'reorder_level',
    'nominal_weight_grams', 'nos_per_tray', 'trays_per_box', 'nos_per_box',
    'nos_per_pouch', 'pouches_per_box',
    'colour', 'standard_cycle_time', 'standard_cavities',
    'tracking_type', 'is_active',
    'tally_stock_item_guid', 'tally_alter_id', 'tally_synced_at', 'item_group_id',
])]
class Item extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'nominal_weight_grams' => 'decimal:4',
            'nos_per_tray' => 'integer',
            'trays_per_box' => 'integer',
            'nos_per_box' => 'integer',
            // Pouch packing standards — pouch-packed products (Wave A).
            'nos_per_pouch' => 'integer',
            'pouches_per_box' => 'integer',
            // Molding standards (expected-output engine) — seconds per shot
            // and pieces per shot.
            'standard_cycle_time' => 'decimal:2',
            'standard_cavities' => 'integer',
            'tracking_type' => ItemTrackingType::class,
            'is_active' => 'boolean',
            'tally_alter_id' => 'integer',
            'tally_synced_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    /** True when this item originated from a Tally masters pull (§3 split-ownership). */
    public function isTallySourced(): bool
    {
        return $this->tally_stock_item_guid !== null;
    }
}
