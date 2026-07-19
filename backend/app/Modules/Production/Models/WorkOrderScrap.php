<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['work_order_id', 'scrap_reason_id', 'quantity', 'cost_impact', 'notes'])]
class WorkOrderScrap extends Model
{
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ScrapReason::class, 'scrap_reason_id');
    }
}
