<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Exceptions\MachineBusyException;
use App\Modules\Production\Exceptions\ProductNotReadyException;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fast shop-floor capture, modeled as a batch lifecycle rather than a
 * single-step form — see PRODUCTION-SUPERVISOR-UX-PLAN.md §1. One machine
 * can run several different items in one shift (mold change in between),
 * so this table intentionally allows multiple rows per machine per shift;
 * there is no unique constraint forcing one row per machine.
 *
 * Every state transition (startBatch/completeBatch, approve/reject) is a
 * conditional `UPDATE ... WHERE <expected prior state>` rather than a bare
 * ->update() — with several people able to act on any of the floor's
 * machines ad hoc (no fixed assignment, confirmed — UX doc §2), two people
 * genuinely can tap the same machine at once, and a stale second write must
 * fail loudly instead of silently double-counting production.
 */
class ShiftProductionEntryService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BomService $boms,
        private readonly DayBinLedgerService $dayBin,
        private readonly ProductReadinessService $readiness,
        private readonly ProductionConfigurationService $configurations,
        private readonly BatchEstimationService $estimation,
        private readonly ProductionDowntimeService $downtime,
        private readonly ProductionStandardResolver $standards,
        private readonly MachineCapabilityService $machineCapability,
    ) {}

    public function paginate(int $perPage = 20, ?ShiftProductionEntryStatus $status = null): LengthAwarePaginator
    {
        return ShiftProductionEntry::query()
            ->with([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason', 'approvedBy',
                'downtimeEvents.reason',
                'tallySyncEntries',
            ])
            ->when($status, function ($query) use ($status) {
                // The approval `status` column defaults to "pending" at row
                // creation regardless of batch_status — an in_progress
                // (not-yet-completed) batch isn't awaiting approval, it just
                // hasn't reached that stage yet. Any status filter implies
                // "completed batches only," so the approval queue never
                // shows a batch that's still running.
                $query->where('status', $status->value)->where('batch_status', BatchStatus::Completed->value);
            })
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Every in-progress batch, one per machine (the startBatch guard keeps
     * it unique), across all shifts and dates — the authoritative machine
     * state for the Shift Floor. Deliberately unpaginated: bounded by the
     * machine count, and a running batch must never be hidden by paging.
     *
     * @return Collection<int, ShiftProductionEntry>
     */
    public function activeBatches(): Collection
    {
        return ShiftProductionEntry::query()
            ->with(['shift', 'workCenter', 'item'])
            ->where('batch_status', BatchStatus::InProgress->value)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Molding standards (standard_cycle_time / standard_cavities) are
     * SNAPSHOTTED from the item master here and never writable through any
     * request afterwards — the entry keeps the standard the shift actually
     * ran against even if the item master changes later. Enforced by
     * construction: no FormRequest carries rules for the standard_* fields
     * (validated() strips any attempt), and this method reads them from the
     * Item row only, never from $data.
     *
     * @param  array{
     *     shift_id: int, work_center_id: int, item_id: int, warehouse_id: int,
     *     production_date?: string, operator_id?: int,
     *     actual_cycle_time?: ?string, active_cavities?: ?int,
     *     colour?: ?string,
     *     material_shortage_override_reason?: ?string,
     * }  $data
     */
    public function startBatch(array $data, ?int $createdBy): ShiftProductionEntry
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // A machine can only physically run one item at a time — reject a
            // second "Start Batch" if this machine already has one in_progress,
            // per PRODUCTION-SUPERVISOR-UX-PLAN.md §2 ("two people can
            // genuinely tap the same machine at once").
            //
            // THE MACHINE ROW IS LOCKED FIRST, and this ordering is the whole
            // protection. Locking the shift_production_entries rows cannot help
            // in the case that matters: when the machine is idle there is no
            // in_progress row to lock, so two concurrent requests both selected
            // nothing, both passed the check, and both inserted. The previous
            // code acknowledged this in its own comment and then did not do it.
            //
            // work_centers always has exactly one row per machine, so it is a
            // lock that exists whether or not a batch does. Every starter
            // serialises on it, and the recheck below therefore runs with the
            // machine held — the second request blocks here, then reads the
            // first request's committed row and is refused.
            WorkCenter::query()
                ->whereKey($data['work_center_id'])
                ->lockForUpdate()
                ->first();

            $alreadyRunning = ShiftProductionEntry::query()
                ->where('work_center_id', $data['work_center_id'])
                ->where('batch_status', BatchStatus::InProgress->value)
                ->lockForUpdate()
                ->first();

            if ($alreadyRunning !== null) {
                // Carries the running batch rather than a bare refusal — the
                // usual cause is a batch the supervisor cannot see (previous
                // shift, someone else's start), and "here is what is running"
                // is the answer they need.
                throw MachineBusyException::make(
                    $alreadyRunning->load(['item', 'workCenter', 'shift'])
                );
            }

            $item = Item::query()->find($data['item_id']);

            // The product-level standard from the factory master. In watch
            // mode this is what a run uses when no machine-product
            // configuration is approved — which is every product today.
            //
            // Resolved BEFORE the readiness gate, not after: the gate has to
            // judge the figures this run will actually use, and for almost
            // every product those live on the standard rather than the item
            // master. Assessed without them, the gate refuses products whose
            // weight and cycle time it is about to snapshot two lines below.
            $standard = $this->standards->resolve($data['item_id'], $data['production_standard_id'] ?? null);
            $packaging = $this->standards->resolvePackaging($standard, $data['production_standard_packaging_id'] ?? null);

            $shift = Shift::query()->find($data['shift_id']);
            $productionDate = $data['production_date']
                ?? $shift?->productionDateFor()
                ?? now()->toDateString();

            // Resolve the approved machine-product configuration. Null means
            // this product has none yet — the run falls back to the item
            // master and is stamped legacy/unconfigured so nothing pretends
            // it ran against an agreed standard.
            //
            // Resolved BEFORE the readiness gate for the same reason the
            // standard is: the gate judges the figures this run will actually
            // use, and once a machine's configuration is approved those ARE
            // its figures. Assessed without it, the gate would refuse a
            // product whose approved configuration carries the very cycle
            // time it says is missing.
            $configuration = $this->configurations->resolve(
                workCenterId: $data['work_center_id'],
                itemId: $data['item_id'],
                moldId: $data['mold_id'] ?? null,
                colour: $data['colour'] ?? null,
                on: $productionDate,
            );

            // The readiness gate, fail-closed: a product whose masters are
            // incomplete must not start, because every downstream figure it
            // would produce (expected output, efficiency, reconciliation,
            // the Tally voucher) is either a dash or a rejection — and by
            // then the shift has already been worked. Severity per check is
            // configurable; see config/production.php → readiness.
            $readiness = $this->readiness->assess(
                $item ?? new Item,
                Warehouse::query()->find($data['warehouse_id']),
                WorkCenter::query()->find($data['work_center_id']),
                $standard,
                $packaging,
                $configuration,
            );

            if (! $readiness['ready']) {
                throw ProductNotReadyException::make($readiness);
            }

            // Bounded override: a supervisor may deviate from the approved
            // standard only within its declared limits, and only with a
            // reason. resolveEffectiveValues() throws otherwise.
            $effective = $this->configurations->resolveEffectiveValues(
                $configuration,
                [
                    'cycle_time' => $data['cycle_time_override'] ?? null,
                    // active_cavities is the pre-configuration field and
                    // still the one the shop-floor form sends. Treated as
                    // the cavity override so the legacy path keeps working;
                    // bounds and the reason requirement only bite once an
                    // approved configuration governs the run.
                    'cavities' => $data['cavities_override'] ?? $data['active_cavities'] ?? null,
                    'reason' => $data['override_reason'] ?? null,
                ],
                // Fallback order: approved machine configuration, then the
                // factory product standard, then the item master. The
                // standard beats the item master because it is the factory's
                // own current figure.
                $standard ?? $item,
            );

            $scheduledHours = $data['scheduled_hours']
                ?? ($shift !== null ? $this->estimation->shiftLengthHours($shift) : null);

            // Planned downtime known BEFORE the run — this is what makes the
            // adjusted target differ from the full-shift target at Start,
            // rather than only explaining the shortfall afterwards.
            $plannedMinutes = $this->downtime->plannedMinutesFor(
                $data['work_center_id'],
                $productionDate,
                $data['planned_downtime'] ?? [],
            );

            // The supervisor's answer to "the bin does not hold enough for
            // this run — start anyway?". Blank is the same as absent: an
            // empty string would read as "a reason was given" downstream.
            $materialShortageReason = trim((string) ($data['material_shortage_override_reason'] ?? '')) !== ''
                ? trim((string) $data['material_shortage_override_reason'])
                : null;

            // Which colour actually ran. The factory workbook carries no
            // reliable colour column, so for most products the masters
            // cannot answer this and the supervisor is asked at Start —
            // their answer wins, because they are looking at the machine.
            // Order: what the supervisor said, then the approved
            // configuration, then the item master. Never defaulted to a
            // colour nobody chose: a wrong colour silently picks the wrong
            // masterbatch and the wrong amber/clear scrap item downstream,
            // and null ("not known") is recoverable where a confident wrong
            // answer is not.
            $colour = $this->firstNonBlank([
                $data['colour'] ?? null,
                $configuration?->colour,
                $item?->colour,
            ]);

            $entry = ShiftProductionEntry::create([
                'shift_id' => $data['shift_id'],
                'work_center_id' => $data['work_center_id'],
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'production_configuration_id' => $configuration?->id,
                // Recorded even with no approved mapping: this pairing of
                // machine and standard is the evidence the factory will
                // later approve a machine-product mapping FROM.
                'production_standard_id' => $standard?->id,
                'production_standard_packaging_id' => $packaging?->id,
                'packaging_mode' => $packaging?->mode,
                'production_date' => $productionDate,
                'batch_number' => $this->generateBatchNumber($data['work_center_id'], $productionDate),
                'batch_status' => BatchStatus::InProgress,
                'quantity_produced' => null,
                'quantity_scrap' => '0',
                // Standards snapshot. Configuration wins over the item
                // master; both are frozen here so a later master edit can
                // never move this run's numbers.
                'standard_cycle_time' => $configuration?->default_cycle_time ?? $standard?->cycle_time ?? $item?->standard_cycle_time,
                'standard_cavities' => $configuration?->default_cavities ?? $standard?->cavities ?? $item?->standard_cavities,
                'actual_cycle_time' => $data['actual_cycle_time'] ?? null,
                'active_cavities' => $effective['cavities'],
                'cycle_time_source' => $effective['cycle_time_source'],
                'cavities_source' => $effective['cavities_source'],
                'override_reason' => $effective['reason'],
                'override_by' => ($effective['cycle_time_source'] === 'override' || $effective['cavities_source'] === 'override')
                    ? $createdBy : null,
                'scheduled_hours' => $scheduledHours,
                'planned_downtime_minutes' => $plannedMinutes,
                // Which formula set produced this entry's figures. Stamped
                // once, never recalculated — see ProductionCalculationEngine.
                'calculation_version' => ProductionCalculationEngine::VERSION_CURRENT,
                'config_snapshot' => [
                    'configuration_id' => $configuration?->id,
                    'configuration_status' => $configuration?->status?->value,
                    // Frozen here so a later item-master edit can never
                    // rewrite what this run was.
                    'colour' => $colour,
                    'effective_cycle_time' => $effective['cycle_time'],
                    'effective_cavities' => $effective['cavities'],
                    'cycle_time_source' => $effective['cycle_time_source'],
                    'cavities_source' => $effective['cavities_source'],
                    'unit_weight_grams' => (string) ($configuration?->unit_weight_grams ?? $standard?->unit_weight_grams ?? $item?->nominal_weight_grams ?? ''),
                    'production_standard_id' => $standard?->id,
                    'packaging_mode' => $packaging?->mode,
                    'nos_per_box' => $packaging?->nos_per_box ?? $item?->nos_per_box,
                    'nos_per_tray' => $packaging?->nos_per_tray ?? $item?->nos_per_tray,
                    'nos_per_pouch' => $packaging?->nos_per_pouch ?? $item?->nos_per_pouch,
                    'pouches_per_box' => $packaging?->pouches_per_box,
                    'trays_per_box' => $packaging?->trays_per_box,
                    'bom_id' => $configuration?->bom_id,
                    'scheduled_hours' => $scheduledHours,
                    'planned_downtime_minutes' => $plannedMinutes,
                    // Explicitly recorded so every downstream reader can see
                    // this run had no agreed standard behind it.
                    'unconfigured' => $configuration === null,
                    // Material-shortage override. Deliberately NOT written to
                    // the override_reason / override_by COLUMNS: those already
                    // carry this run's bounded cycle-time/cavity deviation
                    // ($effective['reason'] above), and a second meaning on
                    // them would corrupt an unrelated audit trail — and read
                    // back as a standards override that never happened. The
                    // snapshot is already the per-run frozen record, so the
                    // pair lives here instead and needs no migration.
                    'material_shortage_override_reason' => $materialShortageReason,
                    'material_shortage_override_by' => $materialShortageReason !== null ? $createdBy : null,
                    // The cavity rule, recorded for the same reason and in the
                    // same place. Written on EVERY run, not only violations:
                    // "this batch was checked against the rule and complied"
                    // and "no rule was in force" are different facts, and a key
                    // that appears only on breaches cannot tell them apart
                    // months later when the rule has since been changed.
                    'machine_cavity_rule' => [
                        'threshold' => $this->machineCapability->threshold(),
                        'restricted_work_center_ids' => $this->machineCapability->restrictedWorkCenterIds(),
                        'standard_cavities' => $standard?->cavities,
                        'applies' => $this->machineCapability->isRestricted($standard?->cavities),
                        'complied' => $this->machineCapability->allows($standard?->cavities, (int) $data['work_center_id']),
                        'overridden_by' => $this->machineCapability->allows($standard?->cavities, (int) $data['work_center_id'])
                            ? null : $createdBy,
                    ],
                ],
                'operator_id' => $data['operator_id'] ?? null,
                'created_by' => $createdBy,
            ]);

            // Attach the planned downtime to the batch now that it exists.
            $this->downtime->attachPlannedToEntry($entry, $data['planned_downtime'] ?? [], $createdBy);

            return $entry->fresh(['shift', 'workCenter', 'item', 'warehouse', 'operator']);
        });
    }

    /**
     * @param  array{
     *     batch_number?: string, quantity_produced: string, quantity_scrap?: string, scrap_reason_id?: int,
     *     nos_per_tray?: int, no_of_trays?: int, nos_per_box?: int, no_of_box?: int,
     *     no_of_pouches?: int, loose_pieces?: int,
     *     helper_name?: string, notes?: string,
     *     actual_cycle_time?: ?string, active_cavities?: ?int,
     *     running_hours?: ?string, qc_rejection_kg?: ?string,
     *     material_consumptions?: array<int, array{item_id: int, warehouse_id: int, quantity_issued_kg: string}>,
     *     scraps?: array<int, array{type: string, quantity_nos?: string, quantity_kg?: string, scrap_reason_id?: int}>,
     *     downtime_events?: array<int, array{downtime_reason_id: int, minutes: string|float|int, note?: ?string}>,
     * }  $data
     */
    public function completeBatch(ShiftProductionEntry $entry, array $data, ?int $completedBy): ShiftProductionEntry
    {
        if ($entry->batch_status !== BatchStatus::InProgress) {
            throw InvalidStatusTransitionException::make(
                'shift production entry batch',
                $entry->batch_status->value,
                BatchStatus::Completed->value,
            );
        }

        return DB::transaction(function () use ($entry, $data, $completedBy) {
            // Closing counts BEFORE the completion write, inside the same
            // transaction. Their over-count guard must see this segment's
            // window, and a rejected closing weight has to abort the whole
            // completion — a batch completed against a bad closing count
            // would report consumption that never happened.
            //
            // Normal completion and handover now share this one path: until
            // it existed, only handover captured a closing weight, so every
            // normally-completed batch left automatic consumed kg null.
            $this->recordClosingDayBin($entry, $data['closing_day_bin'] ?? [], $completedBy);

            $item = Item::query()->find($entry->item_id);
            $quantityProducedKg = $this->toKg($data['quantity_produced'], $item);
            $quantityRejectionKg = isset($data['quantity_scrap'])
                ? $this->toKg($data['quantity_scrap'], $item)
                : null;

            // Concurrency guard: this affects zero rows (and throws) if
            // someone else already completed this batch since the caller
            // loaded it — see class docblock.
            $affected = ShiftProductionEntry::query()
                ->where('id', $entry->id)
                ->where('batch_status', BatchStatus::InProgress->value)
                ->update([
                    // Auto-minted at Start Batch; an empty completion must
                    // never wipe it — the field is exception-override only.
                    'batch_number' => $data['batch_number'] ?? $entry->batch_number,
                    'quantity_produced' => $data['quantity_produced'],
                    'quantity_produced_kg' => $quantityProducedKg,
                    'quantity_scrap' => $data['quantity_scrap'] ?? '0',
                    'quantity_rejection_kg' => $quantityRejectionKg,
                    'scrap_reason_id' => $data['scrap_reason_id'] ?? null,
                    'nos_per_tray' => $data['nos_per_tray'] ?? null,
                    'no_of_trays' => $data['no_of_trays'] ?? null,
                    'nos_per_box' => $data['nos_per_box'] ?? null,
                    'no_of_box' => $data['no_of_box'] ?? null,
                    // Wave A packaging — same one-shot semantics as the
                    // tray/box counts above: only ever written here, so an
                    // empty completion leaves them as they were (null).
                    'no_of_pouches' => $data['no_of_pouches'] ?? null,
                    'nos_per_pouch' => $data['nos_per_pouch'] ?? null,
                    'loose_pieces' => $data['loose_pieces'] ?? null,
                    'helper_name' => $data['helper_name'] ?? null,
                    // Run actuals: an absent key keeps whatever Start Batch
                    // recorded (an explicit null clears it). standard_* are
                    // intentionally NOT settable here — see startBatch().
                    'actual_cycle_time' => array_key_exists('actual_cycle_time', $data) ? $data['actual_cycle_time'] : $entry->actual_cycle_time,
                    'active_cavities' => array_key_exists('active_cavities', $data) ? $data['active_cavities'] : $entry->active_cavities,
                    'running_hours' => $data['running_hours'] ?? null,
                    'qc_rejection_kg' => $data['qc_rejection_kg'] ?? null,
                    'notes' => $data['notes'] ?? $entry->notes,
                    'batch_status' => BatchStatus::Completed->value,
                    'status' => ShiftProductionEntryStatus::Pending->value,
                ]);

            if ($affected === 0) {
                throw InvalidStatusTransitionException::make(
                    'shift production entry batch',
                    BatchStatus::InProgress->value,
                    BatchStatus::Completed->value,
                );
            }

            foreach ($data['material_consumptions'] ?? [] as $line) {
                $entry->materialConsumptions()->create([
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'quantity_issued_kg' => $line['quantity_issued_kg'],
                    'created_by' => $completedBy,
                ]);

                $this->stock->recordIssue(
                    itemId: $line['item_id'],
                    warehouseId: $line['warehouse_id'],
                    quantity: (string) $line['quantity_issued_kg'],
                    reference: "SPE #{$entry->id}",
                    createdBy: $completedBy,
                );
            }

            foreach ($data['scraps'] ?? [] as $line) {
                $entry->scraps()->create([
                    'type' => $line['type'],
                    'quantity_nos' => $line['quantity_nos'] ?? null,
                    'quantity_kg' => $line['quantity_kg'] ?? null,
                    'scrap_reason_id' => $line['scrap_reason_id'] ?? null,
                ]);
            }

            // Downtime logged with the completion — power cuts, mould
            // changes, breakdowns the supervisor reports alongside the
            // counts (owner, 30-Jul: "…i want to do this for efficiency").
            // Recorded through the downtime service so the reason's own
            // rules hold; record() stamps known_before_start = false by
            // construction — these lines explain the run's hours in
            // productionMetrics(), they never rewrite the Start-time
            // target that planned downtime shaped.
            foreach ($data['downtime_events'] ?? [] as $line) {
                $this->downtime->record($entry, $line, $completedBy);
            }

            $unitCost = $this->stock->currentAverageCost($entry->item_id, $entry->warehouse_id);
            $this->stock->recordReceipt(
                itemId: $entry->item_id,
                warehouseId: $entry->warehouse_id,
                quantity: (string) $data['quantity_produced'],
                unitCost: $unitCost,
                reference: "SPE #{$entry->id}",
                createdBy: $completedBy,
            );

            return $entry->fresh([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason',
                'downtimeEvents.reason',
            ]);
        });
    }

    /**
     * Shift handover / segment continuation (Phase 6 traceability):
     * complete-and-continue in one transaction. The outgoing segment is
     * completed exactly as completeBatch would (same math, stock movements
     * and concurrency guard), its day-bin closing counts are recorded, and
     * a child entry opens for the incoming shift with parent_entry_id set,
     * INHERITING the run's identity: batch_number (deliberately NOT
     * re-minted — the batch number is the run's identity, the entry row is
     * the segment), item, machine, warehouse, and the mold-standards
     * snapshot taken at the original Start Batch (never re-read from the
     * item master — the run keeps the standard it actually started
     * against). The closing counts become the child's opening via
     * day_bin_movements (DayBinLedgerService::openingFor reads the
     * parent's closing), so the balance carries without a second entry.
     *
     * Who confirms the handover (incoming vs outgoing supervisor —
     * Vincent Q5) is deliberately open: whoever is authenticated records
     * it, and recorded_by/created_by say who.
     *
     * @param  array{
     *     shift_id: int, production_date?: ?string, operator_id?: ?int,
     *     closing_day_bin?: array<int, array{item_id: int, quantity_kg: string|float}>,
     *     completion: array<string, mixed>,
     * }  $data
     */
    public function handover(ShiftProductionEntry $entry, array $data, ?int $userId): ShiftProductionEntry
    {
        if ($entry->batch_status !== BatchStatus::InProgress) {
            throw InvalidStatusTransitionException::make(
                'shift production entry batch',
                $entry->batch_status->value,
                BatchStatus::InProgress->value.' (handover)',
            );
        }

        return DB::transaction(function () use ($entry, $data, $userId) {
            // Closing counts first: their over-count guard must see only
            // the outgoing segment's window, and a bad count aborts the
            // whole handover before any stock movement happens.
            $this->recordClosingDayBin($entry, $data['closing_day_bin'] ?? [], $userId);

            $completed = $this->completeBatch($entry, $data['completion'], $userId);

            $child = ShiftProductionEntry::create([
                'shift_id' => $data['shift_id'],
                'work_center_id' => $completed->work_center_id,
                'item_id' => $completed->item_id,
                'warehouse_id' => $completed->warehouse_id,
                'production_date' => $data['production_date']
                    ?? Shift::query()->find($data['shift_id'])?->productionDateFor()
                    ?? now()->toDateString(),
                // Inherited, not re-minted — see method docblock.
                'batch_number' => $completed->batch_number,
                'batch_status' => BatchStatus::InProgress,
                'parent_entry_id' => $completed->id,
                'quantity_produced' => null,
                'quantity_scrap' => '0',
                // The Start Batch snapshot carries through the whole run.
                'standard_cycle_time' => $completed->standard_cycle_time,
                'standard_cavities' => $completed->standard_cavities,
                'actual_cycle_time' => $completed->actual_cycle_time,
                'active_cavities' => $completed->active_cavities,
                'operator_id' => $data['operator_id'] ?? null,
                'created_by' => $userId,
            ]);

            return $child->fresh(['shift', 'workCenter', 'item', 'warehouse', 'operator', 'parentEntry']);
        });
    }

    /**
     * The approval chain, each stage a blocking gate: Supervisor submits
     * (completeBatch → pending) → Plant Manager verifies → Accountant
     * reconciles and posts → Tally. Every transition is the same
     * conditional-UPDATE concurrency guard as the batch lifecycle: two
     * approvers acting at once can't double-advance.
     *
     * THE ACCOUNTANT IS FINAL. There is no MD stage — it was removed, and this
     * docblock described it for longer than the code did. Left uncorrected it
     * is the first thing anyone reads when debugging approvals under pressure.
     */
    public function pmApprove(ShiftProductionEntry $entry, int $signedBy): ShiftProductionEntry
    {
        return $this->advance($entry, ShiftProductionEntryStatus::Pending, ShiftProductionEntryStatus::PmApproved, [
            'plant_manager_signed_by' => $signedBy,
            'plant_manager_signed_at' => now(),
        ]);
    }

    /**
     * The accountant's approval IS the posting gate (team decision 2026-07-26,
     * matching the master plan's §4a design): Vincent/accounts verifies and
     * reconciles, and the entry becomes eligible for Tally immediately —
     * production quantities reach the books the same shift, with no MD wait.
     */
    public function accountantApprove(ShiftProductionEntry $entry, int $signedBy): ShiftProductionEntry
    {
        // Optional hard gate (config, default off): an unaccounted-material
        // figure at/over the blocking threshold cannot be posted — reject it
        // back to the floor or correct the entry first.
        $metrics = $this->productionMetrics($entry);
        if ($metrics !== null && ($metrics['blocks_approval'] ?? false)) {
            throw InvalidStatusTransitionException::make(
                'shift production entry',
                sprintf(
                    'unaccounted material %s kg is at/over the blocking tolerance %s kg',
                    $metrics['reconciliation_unaccounted_kg'],
                    config('production.tolerances.unaccounted_blocking_kg'),
                ),
                'approved',
            );
        }

        $fresh = $this->advance($entry, ShiftProductionEntryStatus::PmApproved, ShiftProductionEntryStatus::Approved, [
            'accountant_signed_by' => $signedBy,
            'accountant_signed_at' => now(),
            'approved_by' => $signedBy,
            'approved_at' => now(),
        ]);

        // Announce it; TallySync enqueues the voucher, Production stays
        // unaware.
        event(new ShiftProductionEntryApproved($fresh));

        return $fresh;
    }

    private function advance(
        ShiftProductionEntry $entry,
        ShiftProductionEntryStatus $from,
        ShiftProductionEntryStatus $to,
        array $columns,
    ): ShiftProductionEntry {
        $affected = ShiftProductionEntry::query()
            ->where('id', $entry->id)
            ->where('status', $from->value)
            ->update(['status' => $to->value] + $columns);

        if ($affected === 0) {
            throw InvalidStatusTransitionException::make(
                'shift production entry',
                $entry->status->value,
                $to->value,
            );
        }

        return $entry->fresh([
            'shift', 'workCenter', 'item', 'warehouse',
            'plantManagerSignedBy', 'accountantSignedBy', 'approvedBy',
        ]);
    }

    /**
     * Rejection is allowed from any pre-MD stage and sends the entry back to
     * the supervisor with a reason — it never reaches the next gate.
     */
    public function reject(ShiftProductionEntry $entry, int $rejectedBy, ?string $reason): ShiftProductionEntry
    {
        $affected = ShiftProductionEntry::query()
            ->where('id', $entry->id)
            ->whereIn('status', [
                ShiftProductionEntryStatus::Pending->value,
                ShiftProductionEntryStatus::PmApproved->value,
                ShiftProductionEntryStatus::AccountantApproved->value,
            ])
            ->update([
                'status' => ShiftProductionEntryStatus::Rejected->value,
                'rejection_reason' => $reason,
                'approved_by' => $rejectedBy,
                'approved_at' => now(),
            ]);

        if ($affected === 0) {
            throw InvalidStatusTransitionException::make(
                'shift production entry',
                $entry->status->value,
                ShiftProductionEntryStatus::Rejected->value,
            );
        }

        return $entry->fresh(['shift', 'workCenter', 'item', 'warehouse', 'approvedBy']);
    }

    /**
     * Sync-status write-backs from the Tally agent's ack/fail reports (via
     * TallySyncEventServiceProvider). Deliberately quiet no-ops when the
     * entry isn't in a matching prior state: the agent can legitimately
     * re-report an entry after a network retry, and a repeat ack must never
     * error — unlike the human-driven transitions above, there's no one to
     * show an InvalidStatusTransitionException to.
     */
    public function markSynced(ShiftProductionEntry $entry): void
    {
        ShiftProductionEntry::query()
            ->where('id', $entry->id)
            // "failed" is included so a retried-then-successful sync recovers.
            ->whereIn('status', [ShiftProductionEntryStatus::Approved->value, ShiftProductionEntryStatus::Failed->value])
            ->update(['status' => ShiftProductionEntryStatus::Synced->value]);
    }

    public function markSyncFailed(ShiftProductionEntry $entry): void
    {
        ShiftProductionEntry::query()
            ->where('id', $entry->id)
            ->where('status', ShiftProductionEntryStatus::Approved->value)
            ->update(['status' => ShiftProductionEntryStatus::Failed->value]);
    }

    /**
     * Expected material use per norm vs what the supervisor actually entered
     * — the variance-investigation numbers for the approval screen. Pure
     * computation, no writes. Returns null for a batch that hasn't completed
     * (an in-progress batch has no consumption to compare yet).
     *
     * Norm resolution: the item's active BOM (kg-type component lines only —
     * caps/cartons counted in Nos never sum into kg) wins over the item's
     * nominal weight; no BOM and no weight means no norm, so the expected-
     * side numbers are null while actual/rejection/scrap still report.
     *
     * @return array{
     *     norm_source: ?string, expected_kg: ?string, actual_kg: string,
     *     variance_kg: ?string, variance_pct: ?float, rejection_kg: string,
     *     scrap_kg: string, unaccounted_kg: ?string,
     * }|null
     */
    public function consumptionVariance(ShiftProductionEntry $entry): ?array
    {
        if ($entry->batch_status !== BatchStatus::Completed) {
            return null;
        }

        // No-ops on the approval list, whose paginate() already eager-loads
        // these; only ad-hoc single-entry callers trigger a load here.
        $entry->loadMissing([
            'item', 'scraps',
            // withTrashed: a master cleanup that soft-deletes a material
            // must not blank its UOM and silently reclassify already-issued
            // kg (mirrors the BOM side in expectedConsumptionKg()).
            'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
        ]);

        $actual = $this->consumedMassKg($entry);

        $scrap = '0';
        foreach ($entry->scraps as $line) {
            if ($line->quantity_kg !== null) {
                $scrap = bcadd($scrap, (string) $line->quantity_kg, 4);
            }
        }

        $rejection = $entry->quantity_rejection_kg !== null
            ? bcadd((string) $entry->quantity_rejection_kg, '0', 4)
            : '0';

        [$normSource, $expected] = $this->expectedConsumptionKg($entry);

        $variancePct = null;
        if ($expected !== null && bccomp($expected, '0', 4) !== 0) {
            $variancePct = round((float) bcmul(bcdiv(bcsub($actual, $expected, 8), $expected, 8), '100', 8), 1);
        }

        return [
            'norm_source' => $normSource,
            'expected_kg' => $expected,
            'actual_kg' => $actual,
            'variance_kg' => $expected !== null ? bcsub($actual, $expected, 4) : null,
            'variance_pct' => $variancePct,
            'variance_band' => $variancePct === null ? null : (function () use ($variancePct) {
                $tolerances = config('production.tolerances');
                $abs = abs($variancePct);
                if ($abs <= $tolerances['variance_pct_ok']) {
                    return 'ok';
                }

                return $abs <= $tolerances['variance_pct_watch'] ? 'watch' : 'investigate';
            })(),
            'rejection_kg' => $rejection,
            'scrap_kg' => $scrap,
            'unaccounted_kg' => $expected !== null
                ? bcsub(bcsub(bcsub($actual, $expected, 4), $rejection, 4), $scrap, 4)
                : null,
        ];
    }

    /**
     * The expected-output engine (SHIFT-REDESIGN-FORMULAS.md #22-24 and the
     * QC/reconciliation rows #9/#10/#20) — the "did the machine produce what
     * physics says it should" block, distinct from consumptionVariance()'s
     * norm-based material question. Pure computation, no writes. Null for a
     * batch that hasn't completed (the frontend duplicates the expected_*
     * formula for the live running screen; this backend figure is the
     * authoritative one post-completion).
     *
     * Null-safety rule: any output whose inputs are missing or zero is null,
     * never a fake number — an efficiency computed against a guessed
     * expectation would be worse than no efficiency at all.
     *
     * Rounding rule (two regimes, deliberately different): expected_boxes is
     * the WB2 col W workbook formula and STAYS half-up ROUND — it must keep
     * matching the sheet cell-for-cell, so config('production.packing_rounding')
     * never touches it. That config governs only packing SUGGESTIONS and
     * "vs standard" notes — expected_pouches here and the frontend's packing
     * prefills — where ceil (default) reflects that a part-filled container
     * still needs packing.
     *
     * @return array{
     *     expected_pieces: ?string, expected_boxes: ?int, expected_pouches: ?int,
     *     actual_boxes: ?int, actual_pouches: ?int,
     *     actual_pieces: ?string, efficiency_pct: ?float, efficiency_band: ?string,
     *     downtime_minutes_total: string, net_running_hours: ?string,
     *     rejection_kg_production: ?string, rejection_kg_qc: ?string,
     *     rejection_diff_kg: ?string, lumps_kg: string, issued_kg: string,
     *     good_production_kg: ?string, confirmed_rejection_kg: ?string,
     *     reconciliation_unaccounted_kg: ?string, unaccounted_band: ?string,
     *     blocks_approval: bool,
     * }|null
     */
    public function productionMetrics(ShiftProductionEntry $entry): ?array
    {
        if ($entry->batch_status !== BatchStatus::Completed) {
            return null;
        }

        $entry->loadMissing([
            'item', 'scraps',
            // withTrashed: a master cleanup that soft-deletes a material
            // must not blank its UOM and silently reclassify already-issued
            // kg (mirrors the BOM side in expectedConsumptionKg()).
            'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
            'downtimeEvents.reason',
        ]);

        // Expected pieces = 3600/CT × active cavities × running hours (WB2's
        // EST BOX numerator, always at the STANDARD cycle time — the snapshot
        // taken at Start Batch). Computed as one division at 8dp: chaining
        // 4dp bc truncations loses the second decimal (144000/10.6 must
        // round to 13584.91, not 13584.90).
        $cycleTime = $entry->standard_cycle_time !== null ? (string) $entry->standard_cycle_time : null;
        $cavities = $entry->active_cavities;
        $hours = $entry->running_hours !== null ? (string) $entry->running_hours : null;

        // Downtime logged AT COMPLETION nets out of the hours before the
        // WB2 formula (owner's rule, 30-Jul: a power cut or mould change
        // must not count against efficiency — the paper report nets B/D
        // time out of the day the same way). Only completion-recorded
        // events count (known_before_start = false): planned downtime
        // attached at Start already shaped that screen's adjusted target,
        // and netting it here too would double-count it. Reasons flagged
        // reduces_runtime = false are excluded, mirroring
        // ProductionDowntimeService::hoursFor(). With no such events the
        // hours string is left completely untouched, so a batch without
        // downtime lines computes byte-identically to before this existed.
        $downtimeMinutes = $this->completionDowntimeMinutes($entry);
        $netHours = $hours;
        if ($hours !== null && bccomp($downtimeMinutes, '0', 2) === 1) {
            $netHours = bcsub($hours, bcdiv($downtimeMinutes, '60', 6), 6);
            if (bccomp($netHours, '0', 6) === -1) {
                // Floored at zero — expected output goes honest-null; the
                // raw typed figure stays on running_hours untouched.
                $netHours = '0';
            }
        }

        $expectedPiecesRaw = null;
        if ($cycleTime !== null && bccomp($cycleTime, '0', 4) === 1
            && $cavities !== null && $cavities > 0
            && $netHours !== null && bccomp($netHours, '0', 4) === 1) {
            $expectedPiecesRaw = bcdiv(bcmul(bcmul('3600', (string) $cavities, 4), $netHours, 4), $cycleTime, 8);
        }

        // Expected boxes = ROUND(expected_pieces / pack, 0) — WB2 col W.
        // The entry's own pack size wins (a run packed at a non-standard
        // count must not be measured against the master's), and history
        // never rewrites itself when the master changes later.
        $nosPerBox = $entry->nos_per_box ?? $entry->item?->nos_per_box;
        $expectedBoxes = null;
        if ($expectedPiecesRaw !== null && $nosPerBox !== null && $nosPerBox > 0) {
            $expectedBoxes = (int) $this->bcRoundHalfUp(bcdiv($expectedPiecesRaw, (string) $nosPerBox, 8), 0);
        }

        // Expected pouches = expected_pieces / pouch standard — a packing
        // suggestion, not a workbook figure, so it rounds per
        // production.packing_rounding (see method docblock). The pouch
        // standard lives only on the item master — the entry carries pouch
        // COUNTS, not a per-entry pouch pack size.
        // Entry snapshot wins — same invariant as nos_per_box above.
        $nosPerPouch = $entry->nos_per_pouch ?? $entry->item?->nos_per_pouch;
        $expectedPouches = null;
        if ($expectedPiecesRaw !== null && $nosPerPouch !== null && $nosPerPouch > 0) {
            $expectedPouches = (int) $this->applyPackingRounding(bcdiv($expectedPiecesRaw, (string) $nosPerPouch, 8), 0);
        }

        // Efficiency = actual PIECES / expected pieces × 100 — piece-grain,
        // not the WB2 col Y box ratio it used to be. The owner's live batch
        // proved the box grain wrong (30-Jul screenshot): 14,322 actual
        // pieces against 13,333 expected — a machine running over standard
        // — displayed as "Efficiency 75%" because 3 full boxes were divided
        // by 4 expected, throwing away 5,208 loose pieces and compounding
        // two roundings. Boxes are still reported alongside; only the ratio
        // moved to the honest grain.
        $actualBoxes = $entry->no_of_box;
        $actualPieces = $entry->quantity_produced !== null ? (string) $entry->quantity_produced : null;
        $efficiency = null;
        if ($expectedPiecesRaw !== null && bccomp($expectedPiecesRaw, '0', 8) === 1 && $actualPieces !== null) {
            $efficiency = round((float) bcmul(bcdiv($actualPieces, $expectedPiecesRaw, 8), '100', 8), 1);
        }

        // Rejection: production-side calculated kg vs QC's weighed kg (WB2
        // P/Q/R). When QC weighed it, QC wins as the confirmed figure —
        // assumption flagged in the shared contract.
        $rejectionProduction = $entry->quantity_rejection_kg !== null
            ? bcadd((string) $entry->quantity_rejection_kg, '0', 4)
            : null;
        $rejectionQc = $entry->qc_rejection_kg !== null
            ? bcadd((string) $entry->qc_rejection_kg, '0', 4)
            : null;
        $confirmedRejection = $rejectionQc ?? $rejectionProduction;

        $lumps = '0.0000';
        foreach ($entry->scraps as $line) {
            if ($line->type === ShiftScrapType::Lumps && $line->quantity_kg !== null) {
                $lumps = bcadd($lumps, (string) $line->quantity_kg, 4);
            }
        }

        $issued = $this->consumedMassKg($entry);

        $good = $entry->quantity_produced_kg !== null
            ? bcadd((string) $entry->quantity_produced_kg, '0', 4)
            : null;

        // Material reconciliation: issued − good − confirmed rejection −
        // lumps. Null (not 0) when nothing was issued or good kg is unknown
        // — those are "can't reconcile", not "perfectly reconciled".
        $unaccounted = null;
        if (bccomp($issued, '0', 4) === 1 && $good !== null) {
            $unaccounted = bcsub(bcsub(bcsub($issued, $good, 4), $confirmedRejection ?? '0', 4), $lumps, 4);
        }

        $tolerances = config('production.tolerances');

        // Bands are ruled here so every client colours the same judgement;
        // thresholds live in config/production.php, never in code.
        $efficiencyBand = null;
        if ($efficiency !== null) {
            $efficiencyBand = $efficiency >= $tolerances['efficiency_ok'] ? 'ok'
                : ($efficiency >= $tolerances['efficiency_watch'] ? 'watch' : 'investigate');
        }

        $unaccountedBand = null;
        if ($unaccounted !== null) {
            $unaccountedBand = abs((float) $unaccounted) > $tolerances['unaccounted_kg'] ? 'investigate' : 'ok';
        }

        $blocking = $tolerances['unaccounted_blocking_kg'];
        $blocksApproval = $blocking !== null
            && $unaccounted !== null
            && abs((float) $unaccounted) >= $blocking;

        return [
            'expected_pieces' => $expectedPiecesRaw !== null ? $this->bcRoundHalfUp($expectedPiecesRaw, 2) : null,
            'expected_boxes' => $expectedBoxes,
            'expected_pouches' => $expectedPouches,
            'actual_boxes' => $actualBoxes,
            'actual_pouches' => $entry->no_of_pouches,
            'actual_pieces' => $actualPieces,
            'efficiency_pct' => $efficiency,
            'efficiency_band' => $efficiencyBand,
            // The netted-downtime pair, so the screen can say "expected 4
            // boxes for 7.50 net hours (30 min downtime)". Total is the
            // completion-recorded, runtime-reducing minutes actually netted
            // above; net hours is what fed the formula (floored at zero) —
            // the raw typed figure stays on running_hours.
            'downtime_minutes_total' => $downtimeMinutes,
            'net_running_hours' => $netHours !== null ? $this->bcRoundHalfUp($netHours, 2) : null,
            'rejection_kg_production' => $rejectionProduction,
            'rejection_kg_qc' => $rejectionQc,
            'rejection_diff_kg' => $rejectionProduction !== null && $rejectionQc !== null
                ? bcsub($rejectionProduction, $rejectionQc, 4)
                : null,
            'lumps_kg' => $lumps,
            'issued_kg' => $issued,
            'good_production_kg' => $good,
            'confirmed_rejection_kg' => $confirmedRejection,
            'reconciliation_unaccounted_kg' => $unaccounted,
            'unaccounted_band' => $unaccountedBand,
            'blocks_approval' => $blocksApproval,
        ];
    }

    /**
     * Sum of the downtime minutes logged at completion (known_before_start
     * = false) whose reason actually stops the machine (reduces_runtime) —
     * the figure productionMetrics() nets out of running hours. Reads the
     * loaded relation, never a fresh query: metrics also run against
     * in-memory entries (tests, previews), where an unsaved entry's null id
     * must match nothing.
     */
    private function completionDowntimeMinutes(ShiftProductionEntry $entry): string
    {
        $total = '0.00';
        foreach ($entry->downtimeEvents as $event) {
            if ($event->known_before_start) {
                continue;
            }

            // A missing reason row counts (fail-safe toward netting what
            // the supervisor logged); an explicit reduces_runtime = false
            // reason does not — same rule as ProductionDowntimeService::hoursFor().
            if ($event->reason !== null && ! $event->reason->reduces_runtime) {
                continue;
            }

            $total = bcadd($total, (string) $event->minutes, 2);
        }

        return $total;
    }

    /**
     * The configurable rounding for packing suggestions and "vs standard"
     * notes — production.packing_rounding: ceil (default, a part-filled
     * container still needs packing), round (half-up, same as
     * bcRoundHalfUp), or floor. bcmath-safe on the non-negative quantities
     * this engine deals in. Public because it's the single rounding
     * authority for every packing suggestion any caller derives — the WB2
     * expected-boxes formula deliberately does NOT go through here (see
     * productionMetrics()).
     */
    public function applyPackingRounding(string $value, int $scale = 0): string
    {
        $mode = (string) config('production.packing_rounding', 'ceil');

        if ($mode === 'round') {
            return $this->bcRoundHalfUp($value, $scale);
        }

        // bcmath's own behaviour at $scale is truncation — which IS floor
        // for the non-negative quantities packing deals in.
        $truncated = bcadd($value, '0', $scale);

        // Compare at the value's full precision so ceil only bumps when a
        // real remainder was dropped (an exact 136.000 must stay 136).
        $dot = strpos($value, '.');
        $precision = max($scale, $dot === false ? 0 : strlen($value) - $dot - 1);

        if ($mode === 'floor' || bccomp($value, $truncated, $precision) === 0) {
            return $truncated;
        }

        return bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale, 0), $scale), $scale);
    }

    /**
     * bcmath truncates; the workbook formulas ROUND. Half-up on the
     * non-negative quantities this engine deals in: add 5 at the first
     * dropped digit, then truncate.
     */
    private function bcRoundHalfUp(string $value, int $scale): string
    {
        $offset = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), $scale + 1);

        return bcadd($value, $offset, $scale);
    }

    /**
     * Resolve the consumption norm: [norm_source, expected_kg]. A lazy
     * per-entry BOM lookup — approval lists are small pages, so this stays
     * simpler than a batch preload.
     *
     * @return array{0: ?string, 1: ?string}
     */
    /**
     * Record the day-bin closing weights for a segment.
     *
     * The single path for BOTH normal completion and handover. Consumption is
     *
     *     consumed = opening + loaded − closing − returned
     *
     * so with no closing count the app cannot know what was consumed and
     * honestly reports null. Handover captured it from the start; normal
     * completion did not, which is exactly why automatic consumed kg was
     * blank for every batch that did not hand over.
     *
     * Callers must already be inside a transaction — a rejected closing
     * weight has to roll the completion back with it, never leaving a batch
     * completed against a count the ledger refused.
     *
     * @param  list<array{item_id: int, quantity_kg: string|float|int}>  $lines
     */
    private function recordClosingDayBin(ShiftProductionEntry $entry, array $lines, ?int $userId): void
    {
        foreach ($lines as $line) {
            $this->dayBin->record([
                'work_center_id' => $entry->work_center_id,
                'item_id' => $line['item_id'],
                'shift_production_entry_id' => $entry->id,
                'type' => 'count',
                'quantity_kg' => $line['quantity_kg'],
                'recorded_by' => $userId,
            ]);
        }
    }

    private function expectedConsumptionKg(ShiftProductionEntry $entry): array
    {
        $produced = $entry->quantity_produced !== null ? (string) $entry->quantity_produced : null;
        $hasProduced = $produced !== null && bccomp($produced, '0', 4) !== 0;

        if ($bom = $this->activeBomFor($entry->item_id)) {
            // Soft-deleted component masters still carry their UOM — this is
            // a read-only norm, so a trashed resin line must not zero it.
            // loadMissing, not load: the BOM instance is cached per item for
            // the request (see activeBomFor), so report endpoints iterating
            // hundreds of entries hydrate each BOM's components once instead
            // of re-querying per entry.
            $bom->loadMissing(['lines.component' => fn ($query) => $query->withTrashed()]);

            $kgPerUnit = '0';
            foreach ($bom->lines as $line) {
                if ($this->isMassUom($line->component?->uom)) {
                    $kgPerUnit = bcadd($kgPerUnit, (string) $line->quantity_per, 4);
                }
            }

            // A BOM with no kg-type lines (caps/cartons only) provides no
            // mass norm — fall through to the item weight, don't claim 0.
            if (bccomp($kgPerUnit, '0', 4) === 1) {
                return ['bom', $hasProduced ? bcmul($produced, $kgPerUnit, 4) : null];
            }
        }

        $weightGrams = $entry->item?->nominal_weight_grams;
        if ($weightGrams !== null && bccomp((string) $weightGrams, '0', 4) === 1) {
            return ['item_weight', $hasProduced ? bcdiv(bcmul($produced, (string) $weightGrams, 4), '1000', 4) : null];
        }

        return [null, null];
    }

    /**
     * The items table's `uom` is free text (Tally-sourced masters included),
     * so kg-type detection is by normalized name rather than a lookup table.
     */
    /**
     * One active-BOM lookup per item per request — the resource computes a
     * variance per list row, and pages repeat items (the service is scoped,
     * so this lives exactly one request).
     *
     * @var array<int, ?Bom>
     */
    private array $activeBomCache = [];

    private function activeBomFor(int $itemId): ?Bom
    {
        if (! array_key_exists($itemId, $this->activeBomCache)) {
            $this->activeBomCache[$itemId] = $this->boms->activeFor($itemId);
        }

        return $this->activeBomCache[$itemId];
    }

    /**
     * Sum of the entry's consumption lines that are genuinely mass, in kg.
     *
     * `shift_material_consumptions.quantity_issued_kg` is a misnomer: the
     * Complete Batch form's "Other materials (exceptions)" repeater accepts
     * ANY item and labels the input with that item's own UOM, so a Nos-unit
     * line (cartons, caps, labels, preforms) lands a piece count in a column
     * named kg. The stock issue is correct either way — it moves the item in
     * its own unit — but every kg roll-up here (variance, reconciliation,
     * unaccounted) has to exclude the non-mass lines, exactly as the
     * expected/BOM side already does in expectedConsumptionKg().
     *
     * Fail-safe direction: an item with a blank/unknown UOM is COUNTED. A
     * dropped resin line understates issue and hides material; a stray
     * unlabelled line at worst overstates it visibly.
     */
    private function consumedMassKg(ShiftProductionEntry $entry): string
    {
        $total = '0';
        foreach ($entry->materialConsumptions as $consumption) {
            $uom = $consumption->item?->uom;
            if ($uom !== null && trim($uom) !== '' && ! $this->isMassUom($uom)) {
                continue;
            }

            $total = bcadd($total, (string) $consumption->quantity_issued_kg, 4);
        }

        return $total;
    }

    private function isMassUom(?string $uom): bool
    {
        // Tally masters write "Kgs." with a trailing dot — 90+ live items
        // carry it; without normalization they silently drop out of every
        // kg-family sum (BOM norms, variance).
        return in_array(rtrim(strtolower(trim((string) $uom)), '.'), ['kg', 'kgs', 'kilogram', 'kilograms'], true);
    }

    /**
     * The first candidate that carries an actual value. Whitespace and the
     * empty string are "no answer", not answers — a blank colour stored as
     * "" reads downstream as a colour that was chosen and is empty.
     *
     * @param  list<?string>  $candidates
     */
    private function firstNonBlank(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * {Ymd}-M{machine}-{seq}: unique, human-readable, minted at Start
     * Batch — supervisors never type it (Complete Batch keeps an override
     * for the exception path only). Sequence is per machine per production
     * date; format pending Vincent's confirmation, isolated here so a
     * format change is a one-method edit.
     */
    private function generateBatchNumber(int $workCenterId, string $productionDate): string
    {
        $machine = WorkCenter::query()->find($workCenterId);
        $machineTag = $machine && preg_match('/(\d+)/', (string) $machine->code, $m)
            ? 'M'.str_pad($m[1], 2, '0', STR_PAD_LEFT)
            : 'M'.$workCenterId;

        $date = Carbon::parse($productionDate)->format('Ymd');

        $sequence = ShiftProductionEntry::query()
            ->where('work_center_id', $workCenterId)
            ->whereDate('production_date', $productionDate)
            ->count() + 1;

        do {
            $candidate = sprintf('%s-%s-%03d', $date, $machineTag, $sequence);
            $exists = ShiftProductionEntry::query()->where('batch_number', $candidate)->exists();
            $sequence++;
        } while ($exists);

        return $candidate;
    }

    private function toKg(string $quantityNos, ?Item $item): ?string
    {
        if ($item === null || $item->nominal_weight_grams === null) {
            return null;
        }

        return bcdiv(bcmul($quantityNos, (string) $item->nominal_weight_grams, 4), '1000', 4);
    }
}
