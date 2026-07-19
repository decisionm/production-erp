<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sku', 'name', 'description', 'uom', 'hsn_sac_code', 'reorder_level', 'tracking_type', 'is_active'])]
class Item extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'tracking_type' => ItemTrackingType::class,
            'is_active' => 'boolean',
        ];
    }
}
