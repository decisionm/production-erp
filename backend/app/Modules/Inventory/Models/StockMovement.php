<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'item_id', 'warehouse_id', 'batch_id', 'serial_number_id', 'type', 'quantity', 'unit_cost',
    'reference', 'transfer_group', 'movement_date', 'notes', 'created_by',
])]
class StockMovement extends Model
{
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'movement_date' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(SerialNumber::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
