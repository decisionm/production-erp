<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'shift_id', 'work_center_id', 'item_id', 'warehouse_id', 'production_date',
    'batch_status', 'batch_number', 'quantity_produced', 'quantity_produced_kg',
    'gross_quantity_produced',
    'quantity_scrap', 'quantity_rejection_kg', 'scrap_reason_id',
    'nos_per_tray', 'no_of_trays', 'nos_per_box', 'no_of_box',
    'no_of_pouches', 'nos_per_pouch', 'loose_pieces', 'helper_name',
    'standard_cycle_time', 'actual_cycle_time', 'standard_cavities', 'active_cavities',
    'running_hours', 'qc_rejection_kg', 'tally_sync_entry_id',
    'supervisor_signed_by', 'supervisor_signed_at', 'plant_manager_signed_by', 'plant_manager_signed_at',
    'accountant_signed_by', 'accountant_signed_at',
    'status', 'rejection_reason', 'approved_by', 'approved_at',
    'operator_id', 'notes', 'created_by', 'completed_by', 'parent_entry_id',
    // The quality gate between completion and the PM's approval.
    'quality_reviewed_nos', 'quality_ok_nos', 'quality_rejected_nos',
    'quality_checked_by', 'quality_checked_at', 'quality_note', 'quality_scrap_note',
    // Configurable production: the resolved configuration, the formula set
    // that produced this entry's figures, and the frozen inputs.
    'production_configuration_id', 'calculation_version', 'config_snapshot',
    'cycle_time_source', 'cavities_source', 'override_reason', 'override_by',
    'planned_downtime_minutes', 'scheduled_hours',
    // Audited target adjustment — the replacement for the workbook's
    // unexplained "+1" cells.
    'target_boxes_override', 'target_override_reason', 'target_override_by',
    'production_standard_id', 'production_standard_packaging_id', 'packaging_mode',
])]
class ShiftProductionEntry extends Model
{
    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'planned_downtime_minutes' => 'decimal:2',
            'scheduled_hours' => 'decimal:2',
            'production_date' => 'date',
            'batch_status' => BatchStatus::class,
            'status' => ShiftProductionEntryStatus::class,
            // Wave A packaging: pouch count and left-over loose pieces,
            // captured at Complete Batch alongside the tray/box counts.
            'no_of_pouches' => 'integer',
            'loose_pieces' => 'integer',
            // Expected-output engine — standard_* are Start Batch snapshots
            // from the item master, never editable after; the rest are
            // shop-floor actuals.
            'standard_cycle_time' => 'decimal:2',
            'actual_cycle_time' => 'decimal:2',
            'standard_cavities' => 'integer',
            'active_cavities' => 'integer',
            'running_hours' => 'decimal:2',
            'qc_rejection_kg' => 'decimal:4',
            // Bottles are COUNTED at the quality gate, never weighed — the kg
            // the books need is derived from the run's frozen unit weight.
            'quality_reviewed_nos' => 'integer',
            'quality_ok_nos' => 'integer',
            'quality_rejected_nos' => 'integer',
            'quality_checked_at' => 'datetime',
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

    /**
     * Who counted the batch's output at Complete Batch. The four-eyes rule
     * reads it: the person who counted must not also be the person who
     * passes the quality check on that count.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function qualityCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_checked_by');
    }

    public function materialConsumptions(): HasMany
    {
        return $this->hasMany(ShiftMaterialConsumption::class);
    }

    /** The batch's packed cartons, each with a permanent scannable identity. */
    public function cartons(): HasMany
    {
        return $this->hasMany(FinishedCarton::class);
    }

    /**
     * Sync attempts for this entry, newest first — read-only here; all
     * writes stay in the TallySync module. Exists so a failed entry can
     * surface its Tally error on the approval screen.
     */
    public function tallySyncEntries(): MorphMany
    {
        return $this->morphMany(TallySyncEntry::class, 'syncable')->latest('id');
    }

    /**
     * The shift-level Stock Journal voucher this entry was aggregated into
     * (tally-sync.voucher_granularity = 'shift'). Null under the default
     * 'batch' granularity, where the morph above tracks vouchers instead.
     * Read-only here — all writes stay in the TallySync module.
     */
    public function tallyShiftVoucher(): BelongsTo
    {
        return $this->belongsTo(TallySyncEntry::class, 'tally_sync_entry_id');
    }

    public function scraps(): HasMany
    {
        return $this->hasMany(ShiftScrap::class);
    }

    /**
     * Shift continuity (Phase 6): the segment this one continued from. A
     * run crossing the shift boundary completes the outgoing segment and
     * opens a child inheriting batch_number/item/standards/machine — the
     * batch number stays the run's identity, the entry row is the segment.
     */
    public function parentEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entry_id');
    }

    public function childSegments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entry_id');
    }

    public function dayBinMovements(): HasMany
    {
        return $this->hasMany(DayBinMovement::class);
    }

    /**
     * Downtime logged against this batch: planned events attached at Start
     * (known_before_start = true) and events the supervisor adds at
     * completion — power cuts, mould changes — which net out of running
     * hours in productionMetrics().
     */
    public function downtimeEvents(): HasMany
    {
        return $this->hasMany(ProductionDowntimeEvent::class);
    }
}
