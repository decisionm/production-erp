<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical bag of a material lot. `barcode` is UNIQUE by column — a
 * duplicate scan is a constraint hit, never a silent double-count.
 * remaining_kg is the material still IN the bag (loads decrement, returns
 * flow back); day_bin_work_center_id is set while the bag sits at a
 * machine's day bin. All writes go through TraceabilityService.
 */
#[Fillable([
    'material_lot_id', 'barcode', 'original_kg', 'remaining_kg', 'status',
    'current_warehouse_id', 'day_bin_work_center_id',
])]
class MaterialBag extends Model
{
    protected function casts(): array
    {
        return [
            'original_kg' => 'decimal:4',
            'remaining_kg' => 'decimal:4',
            'status' => MaterialBagStatus::class,
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'material_lot_id');
    }

    public function currentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'current_warehouse_id');
    }

    /**
     * Read-only cross-module relation — which machine's day bin holds the
     * bag right now. Work-center writes stay in Production.
     */
    public function dayBinWorkCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'day_bin_work_center_id');
    }
}
