<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'shift_id', 'work_center_id', 'item_id', 'warehouse_id', 'production_date',
    'batch_status', 'batch_number', 'quantity_produced', 'quantity_produced_kg',
    'quantity_scrap', 'quantity_rejection_kg', 'scrap_reason_id',
    'nos_per_tray', 'no_of_trays', 'nos_per_box', 'no_of_box',
    'supervisor_signed_by', 'supervisor_signed_at', 'plant_manager_signed_by', 'plant_manager_signed_at',
    'accountant_signed_by', 'accountant_signed_at',
    'status', 'rejection_reason', 'approved_by', 'approved_at',
    'operator_id', 'notes', 'created_by',
])]
class ShiftProductionEntry extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'batch_status' => BatchStatus::class,
            'status' => ShiftProductionEntryStatus::class,
            'supervisor_signed_at' => 'datetime',
            'plant_manager_signed_at' => 'datetime',
            'accountant_signed_at' => 'datetime',
            'approved_at' => 'datetime',
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
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supervisorSignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_signed_by');
    }

    public function plantManagerSignedBy(): BelongsTo
    {
        // Repointed from Employee to User when the PM stage became a real
        // app approval (see the approval-chain migration).
        return $this->belongsTo(User::class, 'plant_manager_signed_by');
    }

    public function accountantSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountant_signed_by');
    }

    public function materialConsumptions(): HasMany
    {
        return $this->hasMany(ShiftMaterialConsumption::class);
    }

    public function scraps(): HasMany
    {
        return $this->hasMany(ShiftScrap::class);
    }
}
