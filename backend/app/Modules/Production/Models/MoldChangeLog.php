<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\LogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_center_id', 'shift_id', 'production_date', 'changed_from_item_id', 'changed_to_item_id', 'changed_to_mold_id',
    'from_time', 'to_time', 'total_minutes', 'status', 'created_by',
])]
class MoldChangeLog extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'from_time' => 'datetime',
            'to_time' => 'datetime',
            'status' => LogStatus::class,
        ];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function changedFromItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'changed_from_item_id');
    }

    public function changedToItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'changed_to_item_id');
    }

    public function changedToMold(): BelongsTo
    {
        return $this->belongsTo(Mold::class, 'changed_to_mold_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
