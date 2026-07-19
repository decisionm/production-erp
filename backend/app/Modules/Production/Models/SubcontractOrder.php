<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Enums\SubcontractOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'vendor_id', 'item_id', 'bom_id', 'warehouse_id', 'quantity_planned', 'quantity_received',
    'materials_cost', 'service_cost', 'total_cost', 'status', 'materials_sent_at', 'completed_at', 'created_by',
])]
class SubcontractOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SubcontractOrderStatus::class,
            'materials_sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
        return $this->hasMany(SubcontractOrderMaterial::class);
    }
}
