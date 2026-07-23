<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sku', 'name', 'description', 'uom', 'hsn_sac_code', 'reorder_level',
    'nominal_weight_grams', 'tracking_type', 'is_active',
    'tally_stock_item_guid', 'tally_alter_id', 'tally_synced_at',
])]
class Item extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'nominal_weight_grams' => 'decimal:4',
            'tracking_type' => ItemTrackingType::class,
            'is_active' => 'boolean',
            'tally_alter_id' => 'integer',
            'tally_synced_at' => 'datetime',
        ];
    }

    /** True when this item originated from a Tally masters pull (§3 split-ownership). */
    public function isTallySourced(): bool
    {
        return $this->tally_stock_item_guid !== null;
    }
}
