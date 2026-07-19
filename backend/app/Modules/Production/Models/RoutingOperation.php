<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['routing_id', 'work_center_id', 'sequence', 'name', 'standard_time_minutes'])]
class RoutingOperation extends Model
{
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }
}
