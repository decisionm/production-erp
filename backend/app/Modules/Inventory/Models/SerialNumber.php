<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['item_id', 'serial_number', 'status', 'warehouse_id'])]
class SerialNumber extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SerialNumberStatus::class,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
