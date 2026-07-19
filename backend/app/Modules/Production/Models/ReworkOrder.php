<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ReworkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'item_id', 'source_work_order_id', 'bom_id', 'warehouse_id', 'quantity_input', 'quantity_recovered',
    'material_cost', 'labor_cost', 'total_cost', 'status', 'released_at', 'completed_at', 'created_by',
])]
class ReworkOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ReworkOrderStatus::class,
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function sourceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'source_work_order_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ReworkOrderMaterial::class);
    }
}
