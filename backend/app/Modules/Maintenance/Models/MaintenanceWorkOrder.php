<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\HRMS\Models\Employee;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'asset_id', 'maintenance_schedule_id', 'type', 'status', 'description', 'reported_date',
    'started_at', 'completed_at', 'assigned_to', 'labor_cost', 'parts_cost', 'total_cost', 'created_by',
])]
class MaintenanceWorkOrder extends Model
{
    protected function casts(): array
    {
        return [
            'type' => MaintenanceWorkOrderType::class,
            'status' => MaintenanceWorkOrderStatus::class,
            'reported_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrderPart::class);
    }
}
