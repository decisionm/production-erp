<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
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
    // The Tally identity RESOLVED from the selected packaging at completion
    // (DEC-20260810-003), frozen so a later packaging edit cannot rewrite
    // what a completed batch claims it produced. Null = the product's own
    // item — the pre-feature behaviour, byte for byte.
    'finished_item_id',
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
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * WHAT ONE PIECE OF THIS RUN WEIGHS, in grams — the entry's frozen
     * config_snapshot first (Start Batch resolved configuration → standard →
     * item master and froze the answer there), the item master's
     * nominal_weight_grams only as a fallback for legacy rows whose snapshot
     * predates the key. Every kilogram the server stores for this entry is
     * computed from this figure, and every screen must show the same one
     * (DEC-20260805-005) — which is why this lives once, on the model.
     *
     * The snapshot stores the weight as a string and writes '' when nothing
     * resolved, so blanks are rejected before any bcmath call — and a zero
     * or negative weight is "no weight", not a weight of zero.
     */
    public function resolvedUnitWeightGrams(?Item $item = null): ?string
    {
        $candidates = [
            $this->config_snapshot['unit_weight_grams'] ?? null,
            ($item ?? $this->item)?->nominal_weight_grams,
        ];

        foreach ($candidates as $candidate) {
            $weight = trim((string) ($candidate ?? ''));

            if ($weight === '' || ! is_numeric($weight)) {
                continue;
            }

            if (bccomp($weight, '0', 4) === 1) {
                return $weight;
            }
        }

        return null;
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

    /**
     * The Tally identity frozen at completion, when the selected packaging
     * carries one of its own (DEC-20260810-003). Null on every batch that
     * predates the feature and on every packaging without an identity.
     */
    public function finishedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'finished_item_id');
    }

    /** The packaging option this run froze at Start (or via its shift page row). */
    public function standardPackaging(): BelongsTo
    {
        return $this->belongsTo(ProductionStandardPackaging::class, 'production_standard_packaging_id');
    }

    /**
     * The item this batch's FINISHED GOODS move as — stock receipt, Tally
     * voucher, labels, trace. THE one resolution point: everything that
     * posts or prints the produced item goes through here, so "selection
     * drives the name everywhere" cannot be true on one surface and false
     * on another.
     */
    public function effectiveItemId(): int
    {
        return (int) ($this->finished_item_id ?? $this->item_id);
    }

    /** The resolved item row — finishedItem when frozen, else the product. */
    public function effectiveItem(): ?Item
    {
        if ($this->finished_item_id !== null) {
            $this->loadMissing('finishedItem');

            return $this->finishedItem;
        }

        $this->loadMissing('item');

        return $this->item;
    }

    /**
     * Whether this batch's finished goods post AS a local-only fixture — an
     * item that exists in this database and nowhere in Tally, so a voucher
     * naming it is refused ("Stock Item does not exist").
     *
     * THE ONE PREDICATE. Judged on the item the voucher will actually NAME
     * (effectiveItem(), never the base product): a real product resolving to
     * a fixture packaging identity would post a name Tally cannot accept.
     * Every gate that asks the question — the Tally enqueue guard, the shift
     * sweep, the payload rebuilds, the require_postable_voucher approval
     * exemption — calls this, so no two of them can drift on WHICH item they
     * judge. Null (a product retired after the run) is "not a fixture": its
     * quantities stay on the voucher rather than silently dropping off it.
     */
    public function isLocalFixtureIdentity(): bool
    {
        return $this->effectiveItem()?->isLocalFixture() ?? false;
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The workbook row this run was judged against, and the approved machine
     * exception that may have overridden it.
     *
     * Both ids were already stored on every batch; nothing could read them
     * back as records, so no screen could say "the workbook says 5, this
     * machine is set to 4, the run used 5". That sentence is the whole point
     * of loading them.
     */
    public function productionStandard(): BelongsTo
    {
        return $this->belongsTo(ProductionStandard::class, 'production_standard_id');
    }

    public function productionConfiguration(): BelongsTo
    {
        return $this->belongsTo(ProductionConfiguration::class, 'production_configuration_id');
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

    /**
     * WHEN THIS BATCH'S STANDING COMPLETION WAS RECORDED — derived, because
     * no column stamps it: every other lifecycle stage has its *_at
     * (quality_checked_at, approved_at, …) but completeBatch writes none.
     *
     * The derivation is evidence, not guesswork: the consumption rows are
     * created inside completeBatch's own transaction, and an amendment
     * DELETES them before the corrected completion writes new ones
     * (reverseCompletionEffects) — so the standing rows' newest created_at
     * IS the write instant of the completion currently in force. After an
     * amendment this therefore moves to the re-completion's instant, which
     * is consistent with everything else about corrections here: the
     * standing figures are always the latest calculation and only that.
     *
     * Null when the batch is not completed, or when a completion carried no
     * material lines at all — a missing instant is reported missing, never
     * approximated from updated_at (which every later approval rewrites).
     *
     * Memoized per model instance: the label read renders one batch's whole
     * carton set off a single shared entry object, and the answer must not
     * cost a query per box.
     */
    private bool $batchCompletedAtResolved = false;

    private ?CarbonInterface $batchCompletedAtValue = null;

    public function batchCompletedAt(): ?CarbonInterface
    {
        if (! $this->batchCompletedAtResolved) {
            $this->batchCompletedAtResolved = true;

            if ($this->batch_status === BatchStatus::Completed) {
                $newest = $this->materialConsumptions()->max('created_at');
                $this->batchCompletedAtValue = $newest !== null
                    ? CarbonImmutable::parse($newest, 'UTC')
                    : null;
            }
        }

        return $this->batchCompletedAtValue;
    }

    /** The batch's packed cartons, each with a permanent scannable identity. */
    public function cartons(): HasMany
    {
        return $this->hasMany(FinishedCarton::class);
    }

    /**
     * The one plain word a carton scan renders about this batch's quality
     * and approval truth (DEC-20260807-013): the sticker on the box never
     * changes, so the SYSTEM's answer at scan time is what carries it.
     *
     *   'quality_rejected' — the batch was rejected (quality gate or the
     *                        approval chain sent it back); its boxes must
     *                        not ship and dispatch refuses them.
     *   'approved'         — the accountant approved (Synced/Failed are
     *                        post-approval Tally states; quality-wise the
     *                        batch is through).
     *   'pending'          — still in the chain. Dispatch does NOT block
     *                        these (tightening that gate is open owner
     *                        question Q27) but every scan shows the state.
     */
    public function cartonQualityVerdict(): string
    {
        return match ($this->status) {
            ShiftProductionEntryStatus::Rejected => 'quality_rejected',
            ShiftProductionEntryStatus::Approved,
            ShiftProductionEntryStatus::AccountantApproved,
            ShiftProductionEntryStatus::Synced,
            ShiftProductionEntryStatus::Failed => 'approved',
            default => 'pending',
        };
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
     * The shift-level Stock Journal voucher this entry was aggregated into —
     * populated under the default 'shift' granularity
     * (tally-sync.voucher_granularity); null under 'batch', where the morph
     * above tracks the per-entry vouchers instead.
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
     * How this batch was packed, line by line — the packing_lines the
     * completion was validated against, stored in the same transaction
     * (Phase 5, §4.16 closed) and replaced by an amendment. In the order
     * typed. Empty for every batch completed without lines, and for every
     * batch completed before the table existed.
     */
    public function packingLines(): HasMany
    {
        return $this->hasMany(ShiftProductionEntryPackingLine::class)->orderBy('position');
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
