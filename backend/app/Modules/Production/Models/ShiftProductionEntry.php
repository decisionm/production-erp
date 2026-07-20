<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shift_id', 'work_center_id', 'item_id', 'warehouse_id', 'production_date',
    'quantity_produced', 'quantity_scrap', 'scrap_reason_id', 'operator_id', 'notes', 'created_by',
])]
class ShiftProductionEntry extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scrapReason(): BelongsTo
    {
        return $this->belongsTo(ScrapReason::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
