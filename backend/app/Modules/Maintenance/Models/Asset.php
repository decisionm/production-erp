<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Models\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'category', 'location', 'purchase_date', 'purchase_cost', 'status'])]
class Asset extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:4',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class);
    }
}
