<?php

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Models\Enums\NonConformanceSeverity;
use App\Modules\Quality\Models\Enums\NonConformanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incoming_inspection_id', 'item_id', 'description', 'severity', 'status',
    'quantity_affected', 'raised_by', 'raised_date', 'resolution', 'closed_date',
])]
class NonConformanceReport extends Model
{
    protected function casts(): array
    {
        return [
            'severity' => NonConformanceSeverity::class,
            'status' => NonConformanceStatus::class,
            'quantity_affected' => 'decimal:4',
            'raised_date' => 'date',
            'closed_date' => 'date',
        ];
    }

    public function incomingInspection(): BelongsTo
    {
        return $this->belongsTo(IncomingInspection::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }
}
