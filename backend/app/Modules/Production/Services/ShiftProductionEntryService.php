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
use App\Modules\Production\Models\ShiftScrap;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Fast shop-floor capture, modeled as a batch lifecycle rather than a
 * single-step form — see docs/archive/PRODUCTION-SUPERVISOR-UX-PLAN.md §1. One machine
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
        // Answers "which warehouse" so the floor is never asked — see the
        // class docblock on FactoryWarehouseResolver.
        private readonly FactoryWarehouseResolver $factoryWarehouses,
        // What Tally would refuse, asked before the approval that posts.
        // A cross-module READ through the other module's service, which is
        // the rule (CLAUDE.md) — TallySync owns the voucher shape and
        // nothing about it is duplicated here.
        private readonly VoucherPreviewService $voucherPreview,
        // The bag-cost ANALYTIC layer. It reads this entry's consumption
        // lines and the machine's scanned load layers; it never writes a
        // stock movement and never touches average cost — see that class's
        // boundary note. Nothing in this service's stock handling changes
        // because of it.
        private readonly BagCostAllocationService $bagCosts,
    ) {}

    public function paginate(int $perPage = 20, ?ShiftProductionEntryStatus $status = null, bool $includeCancelled = false): LengthAwarePaginator
    {
        return ShiftProductionEntry::query()
            ->with([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                // Every consumption line carries its own source warehouse
                // (a day bin, a store) and approval shows it per line —
                // ShiftMaterialConsumptionResource emits `warehouse` only
                // whenLoaded, so leaving it out drops the key from the JSON
                // rather than merely costing a query. withTrashed for the
                // same reason as the item above: a godown retired after the
                // batch ran must still be nameable in that batch's history.
                'materialConsumptions.warehouse' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason', 'approvedBy',
                // Loaded so the resource can name all three figure sources
                // apart — workbook, machine exception, and the run's own.
                'productionStandard', 'productionConfiguration', 'cancelledBy',
                'downtimeEvents.reason',
                'tallySyncEntries',
                // The quality queue and the approval queue are the same list
                // read at different stages, so the checker's name has to be
                // in it — the PM's first question about a reduced figure is
                // who reduced it.
                'qualityCheckedBy',
            ])
            // CANCELLED ROWS ARE NOT WORK. This list is the approval queue and
            // the production views; a batch withdrawn as a mistake must leave
            // them the moment it is cancelled.
            //
            // The filter is here rather than only inside the status branch
            // below, which is the bug it fixes: with no status filter there was
            // no batch_status predicate at all, so an unfiltered read returned
            // cancelled batches and entry #40 kept appearing after it had been
            // withdrawn. History keeps them — `include_cancelled` is how the
            // audit view asks, and nothing else passes it.
            ->when(! $includeCancelled, fn ($query) => $query->where('batch_status', '!=', BatchStatus::Cancelled->value))
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
     *     shift_id: int, work_center_id: int, item_id: int, warehouse_id?: ?int,
     *     production_date?: string, operator_id?: int,
     *     actual_cycle_time?: ?string, active_cavities?: ?int,
     *     colour?: ?string,
     *     material_shortage_override_reason?: ?string,
     * }  $data
     */
    public function startBatch(array $data, ?int $createdBy): ShiftProductionEntry
    {
        // WHERE THE FINISHED BOTTLES GO, answered here rather than on the
        // floor (owner, 30-Jul: "there is no need to select any store in any
        // place"). Absent AND explicit-null both route to the resolver: the
        // rule is 'sometimes|nullable', so a client that sends the key with
        // no value means the same thing as one that omits it — "you decide".
        //
        // Resolved BEFORE the transaction opens, and before the readiness
        // gate below, on purpose. The gate calls Warehouse::find() on this id
        // and checks tally_godown; leaving it null until then would surface a
        // missing setting as "this product has no Tally godown", which names
        // the wrong fix. It also means a deployment that cannot resolve fails
        // with a plain 422 without ever taking the work-center lock.
        if (($data['warehouse_id'] ?? null) === null) {
            $data['warehouse_id'] = $this->factoryWarehouses->finishedGoodsOrFail()->id;
        }

        return DB::transaction(function () use ($data, $createdBy) {
            // A machine can only physically run one item at a time — reject a
            // second "Start Batch" if this machine already has one in_progress,
            // per docs/archive/PRODUCTION-SUPERVISOR-UX-PLAN.md §2 ("two people can
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
                        // Codes as well as ids. The ids are what the rule
                        // tested; the codes are what it MEANT, and a snapshot
                        // holding only ids is how "10" came to mean Machine 5
                        // for every batch recorded before this was fixed.
                        'restricted_work_center_codes' => $this->machineCapability->restrictedWorkCenterCodes(),
                        'restricted_work_center_ids' => $this->machineCapability->restrictedWorkCenterIds(),
                        'enforced' => $this->machineCapability->isEnforced(),
                        // THE RULE IS JUDGED ON ACTIVE CAVITIES — the cavities
                        // this run actually uses, which is the factory's own
                        // wording ("if active cavities are 5 or more"). Both
                        // figures are recorded, because the question afterwards
                        // is which one the rule read.
                        'active_cavities' => $effective['cavities'],
                        'standard_cavities' => $standard?->cavities,
                        'applies' => $this->machineCapability->isRestricted($effective['cavities']),
                        // COMPLIANCE AND PERMISSION ARE RECORDED SEPARATELY.
                        // While enforcement is off a non-compliant run is
                        // permitted and still recorded as non-compliant — these
                        // exceptions are the evidence for deciding whether to
                        // switch enforcement on, so collapsing them into one
                        // "it was fine" flag would erase the very thing being
                        // gathered.
                        'complied' => $this->machineCapability->compliesWithRecommendation($effective['cavities'], (int) $data['work_center_id']),
                        'permitted' => $this->machineCapability->isPermitted($effective['cavities'], (int) $data['work_center_id']),
                        'exception_recorded_by' => $this->machineCapability->compliesWithRecommendation($effective['cavities'], (int) $data['work_center_id'])
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
     *     material_consumptions?: array<int, array{item_id: int, warehouse_id?: ?int, quantity_issued_kg: string}>,
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

            // Kg conversion runs on the weight THIS RUN resolved at Start
            // Batch (configuration → standard → item, frozen in
            // config_snapshot), NOT on the item master's own column — see
            // resolvedUnitWeightGrams(). Reading the column directly left
            // quantity_produced_kg null for every Tally-synced product and
            // silently switched the whole reconciliation chain off.
            $item = Item::query()->find($entry->item_id);
            $unitWeightGrams = $this->resolvedUnitWeightGrams($entry, $item);
            $quantityProducedKg = $this->toKg($data['quantity_produced'], $unitWeightGrams);
            $quantityRejectionKg = isset($data['quantity_scrap'])
                ? $this->toKg($data['quantity_scrap'], $unitWeightGrams)
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
                    // WHO CHOSE THE ACTIVE CAVITIES, recomputed here.
                    //
                    // Completion may change active_cavities, and until now
                    // cavities_source was left holding whatever Start Batch
                    // decided. Batch #43 is the proof: a person typed 5 at
                    // completion over a configured 4, and the row still claimed
                    // `configuration` — the snapshot asserting the machine
                    // configuration supplied a number no configuration holds.
                    // A figure a person entered is an override and says so.
                    'cavities_source' => (array_key_exists('active_cavities', $data)
                        && $data['active_cavities'] !== null
                        && (int) $data['active_cavities'] !== (int) $entry->active_cavities)
                            ? 'override'
                            : $entry->cavities_source,
                    'override_by' => (array_key_exists('active_cavities', $data)
                        && $data['active_cavities'] !== null
                        && (int) $data['active_cavities'] !== (int) $entry->active_cavities)
                            ? $completedBy
                            : $entry->override_by,
                    'running_hours' => $data['running_hours'] ?? null,
                    'qc_rejection_kg' => $data['qc_rejection_kg'] ?? null,
                    'notes' => $data['notes'] ?? $entry->notes,
                    // WHO COUNTED THIS OUTPUT. Recorded because the quality
                    // gate's four-eyes rule needs an earlier signature to
                    // compare against, and created_by (who STARTED the run)
                    // is a different question with a frequently different
                    // answer — a batch begun by the day supervisor and
                    // finished by the night one.
                    'completed_by' => $completedBy,
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

            // Material the ledger does not have is a FACT about this shift,
            // not a reason to refuse it — the whole rationale, and the
            // incident that forced it, is in config/production.php 'stock'.
            // Read once here rather than inside StockMovementService so the
            // permission stays this path's, not every issue's.
            $allowNegative = (bool) config('production.stock.allow_negative_on_completion', true);
            $shortfalls = [];

            foreach ($data['material_consumptions'] ?? [] as $index => $line) {
                // WHICH STORE THIS MATERIAL CAME OUT OF, answered per line by
                // the server. Absent or explicit null both mean "you decide";
                // an id the client did send is honoured untouched, so the
                // legacy and Tally-replay paths behave exactly as before.
                //
                // Item-aware because it has to be: kg resin sits in the day
                // bin by the time a machine runs, while packing film counted
                // in Nos never passes through the bin at all — one blanket
                // default would fail one of them on stock it does not have.
                // The field name is the line's own index so the 422 points at
                // the row that could not be resolved, not at the whole array.
                $warehouseId = $line['warehouse_id']
                    ?? $this->factoryWarehouses->consumptionSourceOrFail(
                        (int) $line['item_id'],
                        "material_consumptions.{$index}.warehouse_id",
                    )->id;

                $entry->materialConsumptions()->create([
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $warehouseId,
                    'quantity_issued_kg' => $line['quantity_issued_kg'],
                    'created_by' => $completedBy,
                ]);

                $shortfallKg = null;

                $this->stock->recordIssue(
                    itemId: $line['item_id'],
                    warehouseId: $warehouseId,
                    quantity: (string) $line['quantity_issued_kg'],
                    reference: "SPE #{$entry->id}",
                    createdBy: $completedBy,
                    allowNegative: $allowNegative,
                    shortfallKg: $shortfallKg,
                );

                if ($shortfallKg !== null) {
                    // Names frozen alongside the ids, for the same reason
                    // every other snapshot value is: the screen that flags
                    // this may be read weeks later, after a master rename or
                    // a soft delete, and "short 118.9980 kg of item #592"
                    // is the message this whole change exists to stop
                    // showing anyone.
                    $short = Item::withTrashed()->find($line['item_id']);

                    $shortfalls[] = [
                        'item_id' => (int) $line['item_id'],
                        'item_name' => $short?->name,
                        // THE ITEM'S OWN UNIT, frozen with the name.
                        //
                        // Not every shortfall is kilograms, and the screen used
                        // to say it was: the approval desk read "4 kg of 15ml
                        // Round Master Box" and "28 kg of 60 Ml Tray" (owner,
                        // 06-Aug), which are Nos items — a carton is a carton.
                        // Only the resin and the masterbatch are weighed.
                        //
                        // The stored key stays `short_kg` because snapshots
                        // already written carry it, and the column feeding it is
                        // `quantity_issued_kg` for every kind of line; the unit
                        // is what was missing, not the number.
                        'item_uom' => $short?->uom,
                        'warehouse_id' => (int) $warehouseId,
                        'warehouse_name' => Warehouse::withTrashed()->find($warehouseId)?->name,
                        'short_kg' => $shortfallKg,
                    ];
                }
            }

            if ($shortfalls !== []) {
                // Onto the entry's EXISTING frozen snapshot rather than a
                // new column — the same least-schema route
                // material_shortage_override_reason took at Start, and for
                // the same reason: config_snapshot is already this run's
                // immutable per-batch record and already reaches the
                // resource. save() writes the one dirty attribute, so the
                // conditional UPDATE above remains the only writer of the
                // lifecycle columns.
                $entry->config_snapshot = [
                    ...($entry->config_snapshot ?? []),
                    'stock_shortfalls' => $shortfalls,
                ];
                $entry->save();
            }

            // WHICH BAGS THIS BATCH'S RESIN CAME OUT OF, booked now that the
            // consumption lines exist and inside this same transaction — a
            // completion either books its costs or books nothing.
            //
            // Deliberately AFTER the stock issues above and deliberately
            // without touching them: this is a parallel analytic record, and
            // the ledger's own valuation is not affected by it in any way.
            // An amendment reverses it (reverseCompletionEffects) before
            // re-running this method, so the re-run allocates as the next
            // run rather than doubling the batch's cost.
            $this->bagCosts->allocate($entry, $completedBy);

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

            // THE TALLY IDENTITY THIS BATCH'S FINISHED GOODS MOVE AS
            // (DEC-20260810-003): the selected packaging's own item when it
            // carries one, else the product's — resolved HERE and frozen on
            // the row, so the receipt below, the voucher, the labels and the
            // trace all read one recorded answer instead of re-deriving it
            // against a packaging somebody may since have edited. Re-resolved
            // on every (re-)completion: an amendment reverses the old receipt
            // under the OLD frozen identity first (reverseCompletionEffects),
            // so re-freezing here cannot strand stock under a stale item.
            $entry->finished_item_id = $entry->production_standard_packaging_id === null
                ? null
                : $entry->standardPackaging()->first()?->item_id;
            $entry->save();

            $finishedItemId = $entry->effectiveItemId();
            $unitCost = $this->stock->currentAverageCost($finishedItemId, $entry->warehouse_id);
            $this->stock->recordReceipt(
                itemId: $finishedItemId,
                warehouseId: $entry->warehouse_id,
                quantity: (string) $data['quantity_produced'],
                unitCost: $unitCost,
                reference: "SPE #{$entry->id}",
                createdBy: $completedBy,
            );

            return $entry->fresh([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                // The line's OWN warehouse — which day bin or store the
                // material left. Approval reads it per line; an unloaded
                // relation makes the resource drop the key entirely.
                'materialConsumptions.warehouse' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason',
                'downtimeEvents.reason',
            ]);
        });
    }

    /**
     * AMEND A COMPLETED BATCH — the floor's own correction of its own count,
     * allowed only while the entry is still nobody else's (owner's rule: "in
     * Production Pending, allow the factory user to edit the production entry
     * until QC starts. Once QC has started, the production figures should no
     * longer be edited directly; QC should return it to Production when
     * correction is needed").
     *
     * IT IS A REVERSAL FOLLOWED BY THE ORDINARY COMPLETION, not a second
     * recompute path. completeBatch() does a great deal more than write
     * quantities — it converts pieces to kg at the run's frozen weight, books
     * a finished-goods receipt, issues every consumption line out of the store
     * or day bin it actually came from (negative-tolerant, and recording the
     * shortfall when it goes negative), records closing day-bin counts,
     * downtime and scrap. A parallel "edit the figures" path would have to
     * reproduce all of that and would drift from it on the first change to
     * either. So this method un-books what the wrong completion booked, puts
     * the batch back to in_progress, and then calls completeBatch() itself.
     * The corrected batch is completed by exactly the code that completes
     * every other batch.
     *
     * AND IT IS ONE TRANSACTION, deliberately, rather than a reopen endpoint
     * the floor then re-completes in a second request. A reopened entry is an
     * in_progress row, and in_progress rows are load-bearing elsewhere:
     * startBatch() refuses a new batch on a machine that has one, and the
     * Shift Floor lists them as what is running right now. Reopening a batch
     * on a machine that has since started its next run would put TWO
     * in_progress rows on one machine — the invariant both of those depend on
     * — and the floor would be shown a phantom running batch it could
     * complete by mistake. Inside one transaction the intermediate state is
     * never visible to anyone.
     *
     * AFTERWARDS THE WORLD EQUALS NEVER-HAVING-COMPLETED-WRONG: stock
     * balances, consumption lines, scrap lines and completion downtime are
     * what a single correct completion would have left. The stock LEDGER
     * keeps both the wrong movements and their reversals, because that ledger
     * is append-only by design and "these 130 kg were issued and given back"
     * is the truth of what happened.
     *
     * WHY quality_checked_at IS THE GATE. It is the first moment the figures
     * stop being production's own: the check certifies this count, nets it,
     * and moves stock against it. After that the floor corrects through
     * quality (returnToProduction), never behind its back.
     *
     * @param  array<string, mixed>  $data  a full completion payload, plus an
     *                                      optional amendment_reason
     */
    public function amendCompletion(ShiftProductionEntry $entry, array $data, ?int $amendedBy): ShiftProductionEntry
    {
        $reason = $this->firstNonBlank([$data['amendment_reason'] ?? null]);

        // The gate is read INSIDE the transaction so its row lock is actually
        // held while the reversal below runs — see rowOpenForCorrection().
        return DB::transaction(function () use ($entry, $data, $amendedBy, $reason) {
            $row = $this->rowOpenForCorrection($entry);

            if ($row->quality_checked_at !== null) {
                throw new InvalidStatusTransitionException(
                    'quality has already checked this batch, so its figures are no longer the floor\'s to change — ask quality to return it to production, then correct it',
                );
            }

            // BEFORE ANY MUTATION: refuse a correction that moved the counts
            // and left the material kg exactly as they were.
            $this->refuseStaleMaterialLines($entry, $row, $data);

            $this->reverseCompletionEffects($entry, $row, $amendedBy);

            // The amendment trail, and the shortfall record the wrong
            // completion left, on the entry's own frozen snapshot — written
            // BEFORE the status flip and before completeBatch() runs, because
            // completeBatch() writes that same column (stock_shortfalls) off
            // the model it is handed.
            $snapshot = $row->config_snapshot ?? [];
            unset($snapshot['stock_shortfalls']);
            $snapshot['amendments'] = [
                ...array_values(array_filter((array) ($snapshot['amendments'] ?? []), 'is_array')),
                [
                    'amended_by' => $amendedBy,
                    'amended_at' => now()->toIso8601String(),
                    'reason' => $reason,
                    // HOW MANY OF QUALITY'S RETURNS THIS AMENDMENT ANSWERS —
                    // the one fact that lets correctionHistory() tell "sent
                    // back and not yet fixed" from "sent back and fixed".
                    // Nothing else can: a corrected batch is byte-for-byte the
                    // state a returned one is in (pending, completed, no
                    // check), and quality_returns is never cleared because it
                    // is the audit trail. Counted, not timestamped: both
                    // records stamp whole seconds, and a tie would either
                    // strand the batch out of the quality queue forever or
                    // hide a genuine return from the floor.
                    'answered_returns' => count(
                        array_filter((array) ($snapshot['quality_returns'] ?? []), 'is_array'),
                    ),
                    // What is being corrected, so the PM and the accountant
                    // can see the movement rather than only the final figure.
                    'previous_quantity_produced' => $row->quantity_produced !== null ? (string) $row->quantity_produced : null,
                    'previous_completed_by' => $row->completed_by,
                ],
            ];
            $entry->config_snapshot = $snapshot;
            $entry->save();

            // The same conditional UPDATE guard every other transition uses:
            // if anyone checked, approved or amended this batch since the
            // guards above read it, this affects nothing and the whole
            // reversal rolls back.
            $affected = ShiftProductionEntry::query()
                ->whereKey($entry->id)
                ->where('batch_status', BatchStatus::Completed->value)
                ->where('status', ShiftProductionEntryStatus::Pending->value)
                ->whereNull('quality_checked_at')
                ->update(['batch_status' => BatchStatus::InProgress->value]);

            if ($affected === 0) {
                throw new InvalidStatusTransitionException(
                    'this batch changed while the correction was being saved — open it again and check the figures before correcting',
                );
            }

            return $this->completeBatch($entry->refresh(), $data, $amendedBy);
        });
    }

    /**
     * THE STALE-AMENDMENT REFUSAL — the browser smoke test's bug, made
     * impossible to submit.
     *
     * WHAT WENT WRONG. The correction drawer opens with the stored material
     * kg already in the boxes and LATCHES them, so the resin estimator does
     * not overwrite a figure the store actually weighed out. Correct on its
     * own. But a supervisor then fixes the piece count, watches every derived
     * number on the panel move — good kg, rejection kg, the calculated resin
     * total — and submits. The resin line that posts is the OLD one. The
     * screen showed one arithmetic and the batch got another, and nothing
     * anywhere said so.
     *
     * THE CHOICE MADE HERE: REFUSE, DO NOT RECOMPUTE. This module is
     * advisory-by-construction — completeBatch stores exactly the
     * material_consumptions rows it was handed, and every suggestion service
     * in it carries a docblock swearing it never reaches a stored figure
     * (RunMaterialSuggestionService, MasterbatchDosingService). A server that
     * quietly replaced the submitted kg with its own would break that
     * invariant to fix a client bug, and would then be inventing consumption
     * figures on a shift nobody could audit. So the submitted figure stays
     * the figure — but a correction whose material lines did not move while
     * its output did is sent back with both numbers named, and the supervisor
     * decides which one is true. That is the choice they were never given.
     *
     * IT FIRES ONLY ON THE EXACT SHAPE OF THE BUG, which is why it is a delta
     * and not an absolute check:
     *
     *   - the output moved: (produced + rejected) pieces × the run's frozen
     *     unit weight, plus lumps kg, differs from the stored figures by at
     *     least production.tolerances.amend_material_drift_kg; AND
     *   - the material lines did not: the submitted kg-family total equals
     *     the stored kg-family total to the same tolerance.
     *
     * Comparing TOTALS rather than hunting for "the resin line" is deliberate
     * and is what makes this safe. Masterbatch, and every other material,
     * sits on both sides of the comparison and cancels — so nothing has to
     * identify which line is which, and no name pattern or suggestion service
     * (which answers null on ambiguity, and would silently stop guarding) is
     * involved.
     *
     * DELIBERATELY AMEND-ONLY. A first completion has no previous state to
     * have gone stale against, and gating one would be a floor-wide change to
     * the path every shift runs through.
     *
     * THE ESCAPE HATCH is material_kg_confirmed: a weighed figure that
     * genuinely did not change (the store issued 130 kg; the supervisor is
     * fixing a piece miscount, not the material) is submitted again with that
     * flag and goes through untouched. Until the drawer sends it, this case
     * is a hard 422 — deliberate, on a frozen deploy, because the alternative
     * is the silent wrong figure this exists to stop.
     *
     * @param  array<string, mixed>  $data  the amend payload
     *
     * @throws ValidationException 422 naming both figures
     */
    private function refuseStaleMaterialLines(ShiftProductionEntry $entry, ShiftProductionEntry $row, array $data): void
    {
        // filter_var, not === true: the 'boolean' rule VALIDATES the shape it
        // accepts (true, 1, "1", "true", and their false twins) without
        // casting it, so a strict comparison here would quietly re-refuse an
        // amendment a supervisor had already confirmed — with a message
        // telling them to confirm it.
        if (filter_var($data['material_kg_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $row->loadMissing([
            'scraps',
            // withTrashed for the same reason every other kg roll-up does it:
            // a soft-deleted master must not lose its UOM and silently drop
            // out of (or into) a kilogram sum.
            'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
        ]);

        // Nothing stored to have gone stale — a completion that issued no
        // material has no figure to keep by mistake.
        $storedMass = $this->consumedMassKg($row);
        if (bccomp($storedMass, '0', 4) !== 1) {
            return;
        }

        $tolerance = (string) config('production.tolerances.amend_material_drift_kg', 0.5);

        // The pieces side of the formula, in kg. Rejected pieces are part of
        // it because they were moulded from the same resin — the owner's
        // arithmetic, unchanged: (packed pieces sent to QC + production
        // rejected pieces) × standard weight + lumps.
        $piecesBefore = bcadd(
            (string) ($row->quantity_produced ?? '0'),
            (string) ($row->quantity_scrap ?? '0'),
            4,
        );
        $piecesAfter = bcadd(
            (string) ($data['quantity_produced'] ?? '0'),
            (string) ($data['quantity_scrap'] ?? '0'),
            4,
        );
        $pieceDelta = bcsub($piecesAfter, $piecesBefore, 4);

        $grams = $this->resolvedUnitWeightGrams($entry, Item::query()->find($entry->item_id));

        // No resolved weight means the pieces cannot be turned into kg at
        // all, so a changed count cannot be judged — say nothing rather than
        // guess. (The lumps half below is still in kg and still judged.)
        if ($grams === null && bccomp($pieceDelta, '0', 4) !== 0) {
            return;
        }

        $pieceMassDelta = $grams !== null
            ? bcdiv(bcmul($pieceDelta, $grams, 4), '1000', 4)
            : '0.0000';

        $lumpsDelta = bcsub(
            $this->lumpsKgOfLines($data['scraps'] ?? []),
            $this->lumpsKgOfScraps($row->scraps),
            4,
        );

        $outputDelta = bcadd($pieceMassDelta, $lumpsDelta, 4);

        // The output barely moved — this is a typo fix, not a recount.
        if (bccomp($this->bcAbs($outputDelta), $tolerance, 4) !== 1) {
            return;
        }

        $submittedMass = $this->massOfSubmittedLines($data['material_consumptions'] ?? []);

        // The material lines DID move — the supervisor made a choice about
        // them, whatever it was, and this method has no opinion on it.
        if (bccomp($this->bcAbs(bcsub($submittedMass, $storedMass, 4)), $tolerance, 4) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'material_consumptions' => sprintf(
                'The counts changed but the material kilograms did not. The corrected counts work out to %s kg '
                .'of material, and the form still carries %s kg — the figure the first completion had. '
                .'Check the resin and masterbatch rows against the new counts, or send it again confirming '
                .'the kilograms are right as typed if that is genuinely what the store issued.',
                bcadd($storedMass, $outputDelta, 4),
                $submittedMass,
            ),
        ]);
    }

    /**
     * The kg-family total of a SUBMITTED consumption payload, by exactly the
     * rule consumedMassKg() applies to stored rows — including its fail-safe
     * direction, where an item with a blank or unknown UOM is COUNTED. The
     * two sides of the comparison must be measured the same way or the
     * guard's own arithmetic would manufacture a difference.
     *
     * @param  array<int, array{item_id?: mixed, quantity_issued_kg?: mixed}>  $lines
     */
    private function massOfSubmittedLines(array $lines): string
    {
        $total = '0.0000';

        foreach ($lines as $line) {
            if (! is_array($line) || ! isset($line['item_id'])) {
                continue;
            }

            $uom = Item::withTrashed()->find($line['item_id'])?->uom;
            if ($uom !== null && trim($uom) !== '' && ! $this->isMassUom($uom)) {
                continue;
            }

            $total = bcadd($total, (string) ($line['quantity_issued_kg'] ?? '0'), 4);
        }

        return $total;
    }

    /**
     * Lumps kg out of a submitted scraps payload. Lumps are weighed, never
     * counted, so only quantity_kg is read.
     *
     * @param  array<int, array{type?: mixed, quantity_kg?: mixed}>  $lines
     */
    private function lumpsKgOfLines(array $lines): string
    {
        $total = '0.0000';

        foreach ($lines as $line) {
            if (! is_array($line) || ($line['type'] ?? null) !== ShiftScrapType::Lumps->value) {
                continue;
            }

            $total = bcadd($total, (string) ($line['quantity_kg'] ?? '0'), 4);
        }

        return $total;
    }

    /**
     * Lumps kg off stored scrap rows — the same sum productionMetrics()
     * takes, kept here so the guard reads the stored side identically.
     *
     * @param  iterable<int, ShiftScrap>  $scraps
     */
    private function lumpsKgOfScraps(iterable $scraps): string
    {
        $total = '0.0000';

        foreach ($scraps as $scrap) {
            if ($scrap->type === ShiftScrapType::Lumps && $scrap->quantity_kg !== null) {
                $total = bcadd($total, (string) $scrap->quantity_kg, 4);
            }
        }

        return $total;
    }

    /** |value| at 4dp, without going through a float. */
    private function bcAbs(string $value): string
    {
        return bccomp($value, '0', 4) === -1 ? bcsub('0', $value, 4) : bcadd($value, '0', 4);
    }

    /**
     * Un-book everything a completion booked, so the batch can be completed
     * again from a clean slate.
     *
     * STOCK IS REVERSED WITH COMPENSATING MOVEMENTS, never by deleting the
     * originals: stock_movements is an append-only ledger (see
     * StockMovementService), and the balances it feeds belong to Inventory —
     * Production reaches them through that service, not through its models.
     * Each consumption line is received back at the unit cost ITS OWN issue
     * recorded, so the moving average lands exactly where it started; the
     * finished-goods receipt is issued back out.
     *
     * The finished-goods reversal is negative-tolerant unconditionally, even
     * where completion's own permission would refuse: by the time a wrong
     * count is being corrected, some of what it received may already have
     * moved. Refusing then would leave the entry corrected and the ledger
     * still carrying the wrong receipt, which is worse than a negative
     * balance the accountant can see and fix (config/production.php 'stock').
     *
     * DAY-BIN COUNTS ARE LEFT ALONE, deliberately. A count is an absolute
     * physical observation — somebody put the bin on a scale — and a wrong
     * PIECE count does not make that weighing wrong. The ledger re-anchors on
     * the latest count (DayBinLedgerService::balanceFor/closingFor), and the
     * headroom guard a re-count must pass is opening + loaded − returned,
     * which no count affects, so the corrected completion's own closing count
     * simply supersedes. Deleting them would destroy a real weighing and
     * would take mid-shift counts recorded from the floor screen with it.
     */
    private function reverseCompletionEffects(ShiftProductionEntry $entry, ShiftProductionEntry $row, ?int $userId): void
    {
        $reference = "SPE #{$entry->id}";

        // One pool per (item, warehouse), popped from the END: the live
        // consumption lines belong to the LATEST completion, which after an
        // earlier amendment is not the first issue carrying this reference.
        $pool = $this->stock->issuesForReference($reference)
            ->groupBy(fn ($movement) => "{$movement->item_id}@{$movement->warehouse_id}");

        foreach ($entry->materialConsumptions()->get() as $consumption) {
            $unitCost = $pool->get("{$consumption->item_id}@{$consumption->warehouse_id}")?->pop()?->unit_cost;

            $this->stock->recordReceipt(
                itemId: $consumption->item_id,
                warehouseId: $consumption->warehouse_id,
                quantity: (string) $consumption->quantity_issued_kg,
                unitCost: (string) ($unitCost ?? $this->stock->currentAverageCost(
                    $consumption->item_id,
                    $consumption->warehouse_id,
                )),
                reference: $reference.' amended',
                createdBy: $userId,
            );
        }

        // THE RESIN COST ALLOCATIONS ARE REVERSED, NEVER DELETED — the one
        // place in this reversal that keeps its rows. The consumption lines
        // below are deleted because the corrected completion rewrites them
        // and the stock ledger already records both bookings; the cost
        // allocations instead stamp reversed_at, so the run that was wrong
        // stays readable beside the run that replaced it.
        //
        // Reversing also GIVES THE KILOGRAMS BACK TO THE COMMON RESIN POOL,
        // each at its own frozen rate (BagCostAllocationService::reverse), so
        // the pool afterwards holds what a world in which the wrong
        // completion never happened would have left in it. It runs HERE, and
        // therefore strictly before completeBatch() re-allocates as run N+1
        // inside this same transaction — a re-draw against an unrestored pool
        // would charge the corrected batch at an average the correction
        // itself distorted.
        $this->bagCosts->reverse($entry);

        $entry->materialConsumptions()->delete();

        // The gross figure, because that is what completion received —
        // belt-and-braces only: an amendment is refused once quality has
        // netted anything, so gross is null here in practice.
        $produced = $row->gross_quantity_produced ?? $row->quantity_produced;

        if ($produced !== null && bccomp((string) $produced, '0', 4) === 1) {
            $this->stock->recordIssue(
                // The identity the completion being reversed BOOKED under —
                // the frozen finished_item_id, not a fresh resolution: if the
                // packaging's identity was edited between the completion and
                // this amendment, re-resolving would take the stock back off
                // the wrong item. completeBatch() re-freezes after this runs.
                itemId: $entry->effectiveItemId(),
                warehouseId: $entry->warehouse_id,
                quantity: (string) $produced,
                reference: $reference.' amended',
                createdBy: $userId,
                allowNegative: true,
            );
        }

        // Scrap lines and the downtime the supervisor logged WITH the
        // completion go with it; planned downtime attached at Start Batch
        // (known_before_start) belongs to the run, not to the completion, and
        // stays.
        $entry->scraps()->delete();
        $entry->downtimeEvents()->where('known_before_start', false)->delete();
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

            // Then, for anything nobody weighed, fall back to the ledger
            // rather than to zero — see deriveUncountedClosing(). Must run
            // while the outgoing segment is still in progress: the ledger
            // refuses to back-record into a closed one.
            $openingBasis = $this->deriveUncountedClosing($entry, $data['completion'] ?? []);

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
                // WHERE THE INCOMING SHIFT'S OPENING CAME FROM, per material:
                // 'counted' (somebody weighed the bin) or 'ledger' (derived).
                // Recorded on the child because the child is the segment the
                // figure belongs to, and on config_snapshot because that is
                // already this run's frozen per-segment record — the same
                // least-schema route stock_shortfalls took.
                'config_snapshot' => ['opening_day_bin_basis' => $openingBasis],
            ]);

            return $child->fresh(['shift', 'workCenter', 'item', 'warehouse', 'operator', 'parentEntry']);
        });
    }

    /**
     * HANDOVER WITHOUT A CLOSING COUNT — where the incoming shift's opening
     * comes from when nobody weighed the bin.
     *
     * DayBinLedgerService::openingFor() gives a handover child its parent's
     * closing count, and `?? '0.0000'` when the parent has none. Zero is the
     * wrong answer at a handover and wrong in the expensive direction: the
     * bin does not empty itself because the shift changed. The night shift
     * inherited resin the ledger then said it did not have, its own closing
     * count could be refused as impossible (a count above opening + loaded −
     * returned "means material appeared from nowhere"), and its consumption
     * came out understated by exactly the carry-over — silently, with no
     * flag, because zero looks like a real figure.
     *
     * So: for each material this segment moved that has no count, record the
     * balance the ledger itself implies —
     *
     *     opening + loaded − returned − consumed
     *
     * — as the outgoing segment's closing. Every term is the ledger's own
     * (via consumptionFor/entrySummaryFor); `consumed` is what this very
     * completion says it issued of that material. That figure then becomes
     * the child's opening through the ordinary openingFor() path, so the
     * ledger keeps one rule for openings rather than gaining a second.
     *
     * INVARIANTS KEPT. The derived value can never exceed the count guard's
     * ceiling (opening + loaded − returned): consumption is never negative,
     * so subtracting it can only lower the figure. It is clamped at zero, so
     * a shift that issued more than the bin is recorded as holding cannot
     * hand a negative balance forward. And it re-anchors balanceFor() the
     * same way any count does, because it IS a count row — that is the only
     * movement type the ledger has for an absolute observation.
     *
     * A WEIGHED COUNT ALWAYS WINS. The test is closingFor() === null, not
     * "was this item in the payload", so a count recorded mid-shift from the
     * floor screen counts as weighed too — nothing derived ever overwrites a
     * figure a human put on a scale.
     *
     * recorded_by is deliberately null: a derived figure has no witness, and
     * stamping the handover's user on it would dress an inference up as an
     * observation. The returned basis is what makes it auditable.
     *
     * WHICH MATERIALS, and why the parent's are included. The obvious set is
     * "everything that moved in this segment", and it is wrong by one link:
     * in an ordinary three-shift day the afternoon loads nothing, it just
     * runs down the resin the morning left it. That segment owns no day-bin
     * movements at all, so a set built only from its own would be empty, no
     * closing would be derived, and the NIGHT shift would open at zero —
     * this very defect, one handover further along. The candidates are
     * therefore the union of this segment's materials and its parent's. The
     * chain then sustains itself: each handover leaves a count row owned by
     * the segment that handed over, so the next one always has a parent with
     * something to carry.
     *
     * KNOWN LIMIT: bin activity attached to NO segment
     * (shift_production_entry_id null — e.g. the store refilling a bin
     * before Start Batch) is not a candidate here, so a first segment that
     * only ever ran on such material still hands over at zero. That is a
     * narrower fix than the general problem, chosen deliberately: widening
     * it means deciding which unattributed bin activity belongs to which
     * run, which is DayBinLedgerService's question, not this method's.
     *
     * NOTE for direct service callers: a closing count smuggled in as
     * completion.closing_day_bin is recorded by completeBatch AFTER this
     * runs, so it would win on id order but this basis would still read
     * 'ledger'. HandoverRequest has no such rule, so the HTTP path cannot
     * reach it — closing counts belong at the top level.
     *
     * @param  array<string, mixed>  $completion  the outgoing segment's completion payload
     * @return list<array{item_id: int, basis: string, opening_kg: string}>
     */
    private function deriveUncountedClosing(ShiftProductionEntry $entry, array $completion): array
    {
        // What this completion says it issued, per material. The day bin
        // holds kg materials, so a line matched by item id is in kg.
        $consumedByItem = [];
        foreach ($completion['material_consumptions'] ?? [] as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $consumedByItem[$itemId] = bcadd(
                $consumedByItem[$itemId] ?? '0.0000',
                (string) ($line['quantity_issued_kg'] ?? '0'),
                4,
            );
        }

        // The candidate materials — this segment's, plus the one it
        // continues. See the docblock: without the parent's, the carry-over
        // survives exactly one handover.
        $candidates = [];
        foreach ($this->dayBin->entrySummaryFor($entry)['materials'] as $material) {
            $candidates[(int) $material['item']['id']] = true;
        }

        if ($entry->parent_entry_id !== null) {
            $parent = ShiftProductionEntry::query()->find($entry->parent_entry_id);
            foreach ($parent !== null ? $this->dayBin->entrySummaryFor($parent)['materials'] : [] as $material) {
                $candidates[(int) $material['item']['id']] = true;
            }
        }

        // Deterministic order regardless of which side of the union a
        // material came from.
        ksort($candidates);

        $basis = [];

        foreach (array_keys($candidates) as $itemId) {
            // Read every term from the ledger itself rather than off a
            // summary row: a segment that loaded nothing has no summary row,
            // but consumptionFor() still answers with the opening it
            // inherited.
            $terms = $this->dayBin->consumptionFor($entry, $itemId);

            if ($terms['closing_kg'] !== null) {
                $basis[] = [
                    'item_id' => $itemId,
                    'basis' => 'counted',
                    'opening_kg' => $terms['closing_kg'],
                ];

                continue;
            }

            $remaining = bcsub(
                bcsub(
                    bcadd($terms['opening_kg'], $terms['loaded_kg'], 4),
                    $terms['returned_kg'],
                    4,
                ),
                $consumedByItem[$itemId] ?? '0.0000',
                4,
            );

            // Issued more than the bin is recorded as holding: the stock
            // record is what is wrong (a missed receipt, an opening balance
            // never entered — see config/production.php 'stock'), and the
            // next shift still starts from an empty bin, not a negative one.
            if (bccomp($remaining, '0', 4) === -1) {
                $remaining = '0.0000';
            }

            $this->dayBin->record([
                'work_center_id' => $entry->work_center_id,
                'item_id' => $itemId,
                'shift_production_entry_id' => $entry->id,
                'type' => 'count',
                'quantity_kg' => $remaining,
                'recorded_by' => null,
            ]);

            $basis[] = [
                'item_id' => $itemId,
                'basis' => 'ledger',
                'opening_kg' => $remaining,
            ];
        }

        return $basis;
    }

    /**
     * THE QUALITY GATE — every completed batch passes through it before the
     * plant manager sees it (owner, 30-Jul): "all the machines will go to
     * quality queue, and quality will do the check, add entry as how many
     * reviewed, how many okay and how many rejected. This quality-rejected
     * needs to add in the production as rejected by quality, so the total
     * production will reduce if rejection, otherwise same, then go to next
     * level."
     *
     * WHAT "THE TOTAL PRODUCTION WILL REDUCE" MEANS HERE, precisely, because
     * it is the whole point of the stage and it is easy to implement as a
     * display-only subtraction that fools everybody downstream:
     *
     *   quantity_produced itself becomes the NET figure, and the supervisor's
     *   original count moves to gross_quantity_produced.
     *
     * Not a computed field, not a second column the reports would have to
     * learn about — the one column every existing consumer already reads.
     * The Tally voucher's produced line, the production reports, the shift
     * summary and the approval screen therefore all carry net without any of
     * them being taught about quality, which is the only way to be sure none
     * of them is still quietly posting the gross figure to the books.
     *
     * THE REJECTION IS NOT A SECOND PRECEDENCE PATH. The rejected count is
     * converted to kg at the run's frozen unit weight and written to the
     * EXISTING qc_rejection_kg, so production.rejection_precedence ('qc' —
     * "QC's figure outranks production's") consumes it exactly as it already
     * consumes a supervisor-entered one. Nothing new decides which rejection
     * figure wins, because a second thing deciding that is how two screens
     * end up showing two different rejection totals.
     *
     * AND IT RECONCILES — WITH ONE NAMED EXCEPTION, measured rather than
     * assumed. issued − net − rejected − lumps is arithmetically identical to
     * issued − gross − lumps, so on a batch that carried NO earlier rejection
     * figure the accountant's blocking number does not shift by a gram: the
     * rejected bottles were always part of what came out of the machine, and
     * this stage changes what they are called, not how much mass the shift
     * accounts for.
     *
     * The exception is a batch whose supervisor ALREADY recorded a rejection
     * at completion (quantity_scrap → quantity_rejection_kg, or a weighed
     * qc_rejection_kg). Precedence is 'qc', so QC's figure REPLACES that one
     * rather than joining it — which is the pre-existing rule, not something
     * this stage decided — and the reconciliation therefore moves by the
     * difference between the two. Measured on the fixture: a batch completed
     * with qc_rejection_kg 1.5 reconciles at −0.5000 kg unaccounted; after a
     * 200-piece check writes 2.5800 it reconciles at +1.0000. That is a
     * 1.5000 kg swing — exactly the superseded figure — and it is pinned by
     * test_a_supervisor_rejection_figure_is_superseded_not_added_to() so it
     * can never drift unnoticed. It is the honest consequence of one rejection
     * figure outranking another; the alternative (adding them) would assert
     * the two count different bottles, which nobody at the factory has said.
     *
     * ZERO REJECTION IS A REAL RESULT, not a no-op: the counts and the
     * checker are recorded and the gate opens, but not one figure on the
     * entry moves — no netting, no rejection kg, no stock movement. "Otherwise
     * same", in the owner's words.
     *
     * @param  array{reviewed_nos: int|string, ok_nos: int|string, rejected_nos: int|string, note?: ?string}  $data
     */
    public function recordQualityCheck(ShiftProductionEntry $entry, array $data, ?int $checkedBy): ShiftProductionEntry
    {
        // THE STAGE-OFF REFUSAL, and it belongs HERE rather than only in the
        // screen that hides the button. With the stage stood down the promise
        // is that the chain is exactly what it was before this stage existed;
        // an endpoint that still accepted a check would let one POST net the
        // production figure down and issue bottles out of finished goods on a
        // deployment that had deliberately switched the gate off — and this
        // API is a real product surface (CLAUDE.md §3), not the bundled SPA's
        // private implementation, so "the frontend does not offer it" is not a
        // guard. Refused before anything is read or written.
        if (! (bool) config('production.approvals.quality_stage_enabled', true)) {
            throw new InvalidStatusTransitionException(
                'the quality stage is switched off for this factory — completed batches go straight to the plant manager',
            );
        }

        // Every guard below reads the ROW, never the caller's copy — the same
        // reasoning accountantApprove() spells out: the model in hand was
        // loaded before this request, and a stale value here would wave
        // through exactly the case being refused.
        $row = ShiftProductionEntry::query()
            ->whereKey($entry->id)
            ->first([
                'id', 'status', 'batch_status', 'quantity_produced', 'quality_checked_at', 'completed_by',
                // The figures this check is about to overwrite. Read here so
                // the effects record written at the end of the transaction can
                // state what they WERE — see the quality_check_effects block.
                'quantity_produced_kg', 'qc_rejection_kg',
            ]);

        if ($row === null || $row->batch_status !== BatchStatus::Completed || $row->status !== ShiftProductionEntryStatus::Pending) {
            throw InvalidStatusTransitionException::make(
                'shift production entry quality check',
                $row?->status->value ?? $entry->status->value,
                'quality checked',
            );
        }

        // A SECOND CHECK IS REFUSED, never silently superseded. Superseding
        // reads as the kinder option until you follow the stock: the first
        // check has already issued rejected bottles out of finished goods and
        // received their weight as scrap, so a second one would have to
        // reverse movements that the accountant may already have reconciled
        // against.
        //
        // AND THE CORRECTION ROUTE IS NAMED. This used to end "ask an
        // administrator", because at the time it was written there genuinely
        // was nowhere to send anyone: reject() terminates a batch rather than
        // fixing it, and completeBatch() requires InProgress. returnToProduction()
        // is that missing route — it reverses this check's own stock effects,
        // clears the figures and hands the batch back to the floor — so the
        // sentence now points at it instead of at a person.
        if ($row->quality_checked_at !== null) {
            throw new InvalidStatusTransitionException(
                'this batch has already had its quality check, and a check cannot be redone on top of itself — return it to production first, then check the corrected batch fresh',
            );
        }

        // FOUR EYES, extended to this gate exactly as it binds the accountant.
        // The person who counted the output at Complete Batch must not also
        // be the person who certifies that count, or the check certifies
        // itself. Same config flag relaxes it — see config/production.php.
        if (
            $row->completed_by !== null
            && $checkedBy !== null
            && (int) $row->completed_by === $checkedBy
            && ! (bool) config('production.approvals.allow_same_user', false)
        ) {
            // Plain sentence, not ::make()'s status grammar: the person
            // reading it is a QC checker being told why the button did
            // nothing, and the reason is not a status problem.
            throw new InvalidStatusTransitionException(
                'the same person cannot complete a batch and pass its own quality check',
            );
        }

        $reviewed = (int) $data['reviewed_nos'];
        $ok = (int) $data['ok_nos'];
        $rejected = (int) $data['rejected_nos'];
        $gross = (string) $row->quantity_produced;

        return DB::transaction(function () use ($entry, $row, $data, $checkedBy, $reviewed, $ok, $rejected, $gross) {
            // The weight THIS RUN was quoted and measured against, never the
            // item master's current column — see resolvedUnitWeightGrams().
            $item = Item::query()->find($entry->item_id);
            $unitWeightGrams = $this->resolvedUnitWeightGrams($entry, $item);
            $rejectedKg = $rejected > 0 ? $this->toKg((string) $rejected, $unitWeightGrams) : null;

            $columns = [
                'quality_reviewed_nos' => $reviewed,
                'quality_ok_nos' => $ok,
                'quality_rejected_nos' => $rejected,
                'quality_checked_by' => $checkedBy,
                'quality_checked_at' => now(),
                'quality_note' => $data['note'] ?? null,
            ];

            if ($rejected > 0) {
                $net = bcsub($gross, (string) $rejected, 4);
                $columns['gross_quantity_produced'] = $gross;
                $columns['quantity_produced'] = $net;
                $columns['quantity_produced_kg'] = $this->toKg($net, $unitWeightGrams);

                // Only when a kg figure genuinely resolved. A run with no
                // unit weight cannot state its rejection in kg, and writing
                // null here would ERASE a weighed figure the supervisor may
                // have entered at completion — losing information in the name
                // of recording some.
                if ($rejectedKg !== null) {
                    $columns['qc_rejection_kg'] = $rejectedKg;
                }
            }

            // Same conditional-UPDATE guard as every other transition, plus
            // the null check that makes the double-check refusal above hold
            // under a genuine race rather than only in the common case.
            //
            // AND THE COUNT THIS CHECK WAS COMPUTED AGAINST. $gross was read
            // before the transaction, and amendCompletion() can move
            // quantity_produced on a batch that is still pending, still
            // completed and still unchecked — it reverses the completion,
            // re-runs it, and hands back a row passing every other guard here.
            // A check that squeezed into that window would write
            // `quantity_produced = staleGross − rejected` over the corrected
            // figure and issue bottles out of finished goods against a count
            // that no longer exists. Pinning the gross figure makes the
            // amendment win and this check fail closed, which is the right way
            // round: the corrected count is the true one, and the checker is
            // told to look again.
            $affected = ShiftProductionEntry::query()
                ->where('id', $entry->id)
                ->where('status', ShiftProductionEntryStatus::Pending->value)
                ->where('batch_status', BatchStatus::Completed->value)
                ->where('quantity_produced', $gross)
                ->whereNull('quality_checked_at')
                ->update($columns);

            if ($affected === 0) {
                // Deliberately not the "already checked" sentence the guard
                // above throws: reaching here means the batch moved under the
                // check — someone else checked it, or the floor corrected its
                // figures — and the checker needs to re-read the count before
                // certifying anything, not be told a check already exists.
                throw new InvalidStatusTransitionException(
                    'this batch changed while the check was being saved — open it again and read the produced count before passing it',
                );
            }

            $scrapNote = null;
            // WHAT THIS CHECK ACTUALLY DID, recorded as it happens so
            // returnToProduction() can undo exactly that and nothing else.
            // Every one of these is conditional — the scrap receipt is
            // skipped when no scrap item is configured or the run resolved
            // no unit weight, and a supervisor-entered rejected_finished_good
            // scrap line is indistinguishable from this stage's afterwards —
            // so the reversal must not infer them from the final state. The
            // pre-check figures are here for the same reason: qc_rejection_kg
            // may have carried a weighed figure from completion that this
            // check overwrote, and "restore to null" would erase it.
            $effects = [
                'fg_issue_nos' => null,
                'scrap_item_id' => null,
                'scrap_kg' => null,
                'scrap_row_id' => null,
                'pre_check' => [
                    'quantity_produced' => $gross,
                    'quantity_produced_kg' => $row->quantity_produced_kg !== null ? (string) $row->quantity_produced_kg : null,
                    'qc_rejection_kg' => $row->qc_rejection_kg !== null ? (string) $row->qc_rejection_kg : null,
                ],
            ];

            if ($rejected > 0) {
                // OUT OF FINISHED GOODS. Completion received the gross count
                // into the FG warehouse; these pieces are not sellable
                // product and must stop being counted as it.
                //
                // allow_negative is the COMPLETION path's permission, reused
                // deliberately rather than duplicated: this issue is that
                // receipt's counterpart, reversing part of what completion
                // booked. If the recorded balance cannot cover it, the
                // bottles were still made and still rejected — that is a fact
                // about the shift, and refusing it would strand the whole
                // approval chain behind a stock-record problem the QC desk
                // cannot fix (see config/production.php 'stock' for the
                // incident that settled this).
                $this->stock->recordIssue(
                    itemId: $entry->item_id,
                    warehouseId: $entry->warehouse_id,
                    quantity: (string) $rejected,
                    reference: "QC #{$entry->id}",
                    createdBy: $checkedBy,
                    allowNegative: (bool) config('production.stock.allow_negative_on_completion', true),
                );

                $effects['fg_issue_nos'] = (string) $rejected;

                // AND INTO SCRAP — "no, go to the rejected scrap only".
                // Mass out of FG equals mass into scrap, both derived from
                // the one frozen unit weight, so the pair can never create
                // stock on net.
                $scrapItem = $this->resolveScrapItem();

                if ($scrapItem === null) {
                    $scrapNote = 'Scrap receipt skipped: no scrap item is configured (production.scrap.rejected_item_sku). The rejection is recorded and the bottles are out of finished goods; the scrap weight is not yet on any item.';
                } elseif ($rejectedKg === null) {
                    $scrapNote = 'Scrap receipt skipped: this run resolved no unit weight, so the rejected pieces cannot be converted to a scrap weight. The rejection is recorded and the bottles are out of finished goods.';
                } else {
                    $this->stock->recordReceipt(
                        itemId: $scrapItem->id,
                        warehouseId: $entry->warehouse_id,
                        // At the scrap item's OWN running average, so
                        // receiving this weight does not restate what the
                        // factory's existing scrap is held at. Valuing it at
                        // finished-goods cost would book rejected bottles as
                        // if they were still worth a finished bottle; the
                        // accountant's real scrap valuation rules are not
                        // codified anywhere yet (Tally master plan §1.5).
                        quantity: $rejectedKg,
                        unitCost: $this->stock->currentAverageCost($scrapItem->id, $entry->warehouse_id),
                        reference: "QC #{$entry->id}",
                        createdBy: $checkedBy,
                    );

                    $effects['scrap_item_id'] = $scrapItem->id;
                    $effects['scrap_kg'] = $rejectedKg;
                }

                // The existing scrap bucket, not a new one: ShiftScrap rows
                // of this type already ride the Tally voucher as data and
                // narration, so the rejection reaches the accountant's
                // voucher in words WITHOUT altering the payload's shape.
                $scrapLine = $entry->scraps()->create([
                    'type' => ShiftScrapType::RejectedFinishedGood->value,
                    'quantity_nos' => $rejected,
                    'quantity_kg' => $rejectedKg,
                ]);

                // By id, not by type: the supervisor may have entered a
                // rejected_finished_good line of their own at completion, and
                // a return that deleted "the rejected line" would take theirs.
                $effects['scrap_row_id'] = $scrapLine->id;
            }

            if ($scrapNote !== null) {
                // Written onto the ENTRY, not only to the log: a skipped
                // stock movement that lives in a log file is a skipped stock
                // movement nobody finds. The approval screen shows this to
                // the PM and the accountant before they sign.
                Log::warning('Quality rejection recorded without a scrap receipt', [
                    'shift_production_entry_id' => $entry->id,
                    'rejected_nos' => $rejected,
                    'rejected_kg' => $rejectedKg,
                    'reason' => $scrapNote,
                ]);

                ShiftProductionEntry::query()
                    ->whereKey($entry->id)
                    ->update(['quality_scrap_note' => $scrapNote]);
            }

            // Onto the entry's existing frozen snapshot, the same least-schema
            // route stock_shortfalls and the material-shortage override took,
            // and written through the model because config_snapshot is a cast
            // array — the conditional UPDATE above stays the only writer of
            // the lifecycle columns.
            $entry->config_snapshot = [
                ...($entry->config_snapshot ?? []),
                'quality_check_effects' => $effects,
            ];
            $entry->save();

            return $entry->fresh([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                'materialConsumptions.warehouse' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason',
                'downtimeEvents.reason',
                'qualityCheckedBy',
            ]);
        });
    }

    /**
     * QUALITY RETURNS A BATCH TO PRODUCTION — the other half of the owner's
     * rule. Once quality has the batch, production may not edit its figures
     * behind them; when something is wrong, quality hands it back with a
     * reason and the floor corrects it (amendCompletion) in the open.
     *
     * ALLOWED INSTEAD OF A CHECK, AND ALSO AFTER ONE. Before any check it is
     * simply "these figures are wrong, fix them" — nothing of quality's to
     * undo, and the floor's amend window (which closes at quality_checked_at)
     * was never shut. After a check it is quality correcting THEMSELVES: the
     * check's own stock effects are reversed, its counts cleared and the
     * production figures restored to what they were before it netted them, so
     * whatever comes back is checked fresh rather than checked on top of a
     * half-applied rejection.
     *
     * REFUSED ONCE THE PLANT MANAGER HAS SIGNED. From pm_approved on, the
     * batch has an approval on it and a desk that owns the correction:
     * rejecting it back down the chain is that route, and it is the one that
     * leaves a signature. Quality quietly unwinding an approved batch would
     * make the PM's signature describe figures that no longer exist.
     *
     * WHAT IS RECORDED. The reason is required by the request, and lands on
     * the entry's frozen snapshot with who returned it, when, and whether a
     * check was cleared — the same least-schema route every other per-run
     * audit fact takes here (material_shortage_override_reason,
     * stock_shortfalls, opening_day_bin_basis). It is read back through
     * correctionHistory() so the floor is told WHY their batch came back, and
     * the whole history survives the correction rather than being overwritten
     * by the next one.
     */
    public function returnToProduction(ShiftProductionEntry $entry, string $reason, ?int $returnedBy): ShiftProductionEntry
    {
        // Same stage-off refusal recordQualityCheck() makes, and for the same
        // reason: with the stage stood down the chain must be exactly what it
        // was before quality existed, and this endpoint would otherwise let
        // one POST unwind a batch on a deployment that switched quality off.
        if (! (bool) config('production.approvals.quality_stage_enabled', true)) {
            throw new InvalidStatusTransitionException(
                'the quality stage is switched off for this factory — completed batches go straight to the plant manager, so there is nothing for quality to return',
            );
        }

        // Inside the transaction for the row lock, exactly as amendCompletion()
        // does and for the same reason: this route also reverses stock before
        // it reaches its conditional UPDATE.
        return DB::transaction(function () use ($entry, $reason, $returnedBy) {
            $row = $this->rowOpenForCorrection($entry);
            $hadCheck = $row->quality_checked_at !== null;

            // A check recorded BEFORE this correction feature existed wrote
            // no quality_check_effects record, so what it moved cannot be
            // safely un-moved: the FG side has a legacy fallback but the
            // scrap receipt does not, and reversing half of a check leaves
            // the scrap balance inflated with the audit line that would
            // explain it deleted. Refuse only where that danger is real — a
            // legacy check that rejected nothing moved no stock, and its
            // return is a plain column clear.
            if ($hadCheck
                && ! is_array($row->config_snapshot['quality_check_effects'] ?? null)
                && ($row->quality_rejected_nos ?? 0) > 0) {
                throw new InvalidStatusTransitionException(
                    'this batch\'s quality check was recorded before returns existed, so what it moved cannot be safely un-moved — reject the batch back to the supervisor instead, or let it continue as checked',
                );
            }

            $columns = [
                'quality_reviewed_nos' => null,
                'quality_ok_nos' => null,
                'quality_rejected_nos' => null,
                'quality_checked_by' => null,
                'quality_checked_at' => null,
                'quality_note' => null,
                'quality_scrap_note' => null,
            ];

            if ($hadCheck) {
                $columns = [...$columns, ...$this->reverseQualityCheckEffects($entry, $row, $returnedBy)];
            }

            // The usual conditional UPDATE, scoped to the state the guards
            // read — including whether a check was there — so a check
            // recorded (or cleared) in the meantime loses rather than being
            // silently reversed twice.
            $affected = ShiftProductionEntry::query()
                ->whereKey($entry->id)
                ->where('status', ShiftProductionEntryStatus::Pending->value)
                ->where('batch_status', BatchStatus::Completed->value)
                ->when(
                    $hadCheck,
                    fn ($query) => $query->whereNotNull('quality_checked_at'),
                    fn ($query) => $query->whereNull('quality_checked_at'),
                )
                ->update($columns);

            if ($affected === 0) {
                throw new InvalidStatusTransitionException(
                    'this batch changed while the return was being saved — open it again and look at it before returning it',
                );
            }

            $snapshot = $row->config_snapshot ?? [];
            // The check that was just undone has no effects left to undo.
            unset($snapshot['quality_check_effects']);
            $snapshot['quality_returns'] = [
                ...array_values(array_filter((array) ($snapshot['quality_returns'] ?? []), 'is_array')),
                [
                    'returned_by' => $returnedBy,
                    'returned_at' => now()->toIso8601String(),
                    'reason' => $reason,
                    'cleared_quality_check' => $hadCheck,
                ],
            ];
            $entry->config_snapshot = $snapshot;
            $entry->save();

            return $entry->fresh([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
                'materialConsumptions.warehouse' => fn ($query) => $query->withTrashed(),
                'scraps.scrapReason',
                'downtimeEvents.reason',
            ]);
        });
    }

    /**
     * Undo a quality check's stock and scrap effects, and give back the
     * columns that restore the production figures it netted.
     *
     * READ FROM THE RECORD THE CHECK WROTE, never inferred from the final
     * state. Every effect is conditional: the scrap receipt is skipped when
     * no scrap item is configured or the run resolved no unit weight, the
     * check's own scrap LINE is indistinguishable by type from one the
     * supervisor entered at completion, and qc_rejection_kg may have carried
     * a weighed figure from completion that the check overwrote. Guessing any
     * of those wrong leaves stock or an audit line quietly wrong, so
     * recordQualityCheck() writes down what it did and this reads it back.
     *
     * Legacy rows checked before that record existed fall back to the gross
     * figure — the one thing the final state does state unambiguously.
     *
     * @return array<string, mixed> columns restoring the pre-check figures
     */
    private function reverseQualityCheckEffects(ShiftProductionEntry $entry, ShiftProductionEntry $row, ?int $userId): array
    {
        $effects = $row->config_snapshot['quality_check_effects'] ?? null;
        $effects = is_array($effects) ? $effects : [];

        // The rejected bottles go back into finished goods — the exact
        // counterpart of the issue the check made.
        $fgNos = $effects['fg_issue_nos']
            ?? (($row->quality_rejected_nos ?? 0) > 0 ? (string) $row->quality_rejected_nos : null);

        if ($fgNos !== null && bccomp((string) $fgNos, '0', 4) === 1) {
            $this->stock->recordReceipt(
                itemId: $entry->item_id,
                warehouseId: $entry->warehouse_id,
                quantity: (string) $fgNos,
                unitCost: $this->stock->currentAverageCost($entry->item_id, $entry->warehouse_id),
                reference: "QC #{$entry->id} returned",
                createdBy: $userId,
            );
        }

        // And the scrap weight comes back out — only where the receipt
        // genuinely happened.
        if (($effects['scrap_item_id'] ?? null) !== null && ($effects['scrap_kg'] ?? null) !== null
            && bccomp((string) $effects['scrap_kg'], '0', 4) === 1) {
            $this->stock->recordIssue(
                itemId: (int) $effects['scrap_item_id'],
                warehouseId: $entry->warehouse_id,
                quantity: (string) $effects['scrap_kg'],
                reference: "QC #{$entry->id} returned",
                createdBy: $userId,
                // The scrap this receipt created may already have been moved
                // on; see reverseCompletionEffects() for why a reversal is
                // never refused on a balance.
                allowNegative: true,
            );
        }

        $scrapRowId = $effects['scrap_row_id'] ?? null;

        if ($scrapRowId === null && ($row->quality_rejected_nos ?? 0) > 0) {
            // Legacy only: the newest line matching what the check recorded.
            $scrapRowId = $entry->scraps()
                ->where('type', ShiftScrapType::RejectedFinishedGood->value)
                ->where('quantity_nos', $row->quality_rejected_nos)
                ->orderByDesc('id')
                ->value('id');
        }

        if ($scrapRowId !== null) {
            $entry->scraps()->whereKey($scrapRowId)->delete();
        }

        $preCheck = is_array($effects['pre_check'] ?? null) ? $effects['pre_check'] : [];

        // Gross is what the supervisor counted; it is only written when the
        // check actually netted something, so a zero-rejection check restores
        // to the figures already on the row.
        $produced = $preCheck['quantity_produced']
            ?? ($row->gross_quantity_produced !== null ? (string) $row->gross_quantity_produced : null)
            ?? ($row->quantity_produced !== null ? (string) $row->quantity_produced : null);

        $producedKg = array_key_exists('quantity_produced_kg', $preCheck)
            ? $preCheck['quantity_produced_kg']
            : ($produced !== null
                ? $this->toKg($produced, $this->resolvedUnitWeightGrams($entry, Item::query()->find($entry->item_id)))
                : null);

        return [
            'quantity_produced' => $produced,
            'quantity_produced_kg' => $producedKg,
            // Restored, not blanked: the supervisor may have weighed a
            // rejection at completion that the check overwrote.
            'qc_rejection_kg' => $preCheck['qc_rejection_kg'] ?? null,
            'gross_quantity_produced' => null,
        ];
    }

    /**
     * The shared gate both correction routes stand behind: the batch must be
     * completed, still pending, still nobody's but production's and quality's,
     * and not yet part of anything Tally has been told about.
     *
     * Every check reads the ROW rather than the caller's model, for the reason
     * accountantApprove() spells out — the model in hand was loaded before this
     * request, and a stale value here would wave through exactly the case being
     * refused.
     *
     * AND IT TAKES THE ROW LOCK, because unlike every other transition here the
     * two correction routes do destructive work (reversing stock, deleting
     * consumption and scrap lines) BEFORE they reach their conditional UPDATE.
     * A conditional UPDATE only protects what comes after it. Two amendments
     * overlapping would both read the same live consumption lines, both receipt
     * them back, and the second would then find the row restored to
     * completed/pending/unchecked by the first and sail through its guard —
     * double-reversing one completion's issues. Called as the first statement
     * inside each route's transaction, the lock serialises them: the second
     * waits, then reads the state the first actually left.
     */
    private function rowOpenForCorrection(ShiftProductionEntry $entry): ShiftProductionEntry
    {
        $row = ShiftProductionEntry::query()
            ->whereKey($entry->id)
            ->lockForUpdate()
            ->first([
                'id', 'status', 'batch_status', 'quantity_produced', 'quantity_produced_kg',
                // quantity_scrap is load-bearing for refuseStaleMaterialLines():
                // its pieces-before side is produced + rejected, and a column
                // missing from this list silently reads null — which made the
                // guard blame the supervisor for 0.612 kg that was never
                // theirs (found by typing the figure into a real browser).
                'quantity_scrap',
                'gross_quantity_produced', 'qc_rejection_kg', 'quality_checked_at',
                'quality_rejected_nos', 'completed_by', 'tally_sync_entry_id', 'config_snapshot',
            ]);

        if ($row === null || $row->batch_status !== BatchStatus::Completed) {
            throw new InvalidStatusTransitionException(
                'this batch is still running — there are no completed figures to correct yet',
            );
        }

        if ($row->status !== ShiftProductionEntryStatus::Pending) {
            throw new InvalidStatusTransitionException(match ($row->status) {
                ShiftProductionEntryStatus::PmApproved => 'the plant manager has already approved this batch — it has to be rejected back to the floor before its figures can change',
                ShiftProductionEntryStatus::AccountantApproved,
                ShiftProductionEntryStatus::Approved => 'the accounts desk has already approved this batch and its voucher is on its way to Tally — its figures can no longer be changed here',
                ShiftProductionEntryStatus::Synced => 'this batch is already in Tally — its figures can no longer be changed here; the correction has to be made in Tally',
                ShiftProductionEntryStatus::Failed => 'this batch was approved and its Tally voucher failed — sort the voucher out; its figures can no longer be changed here',
                ShiftProductionEntryStatus::Rejected => 'this batch was rejected back to the supervisor — a rejected batch is out of the chain and cannot be corrected',
                default => 'this batch is no longer waiting for approval, so its figures can no longer be changed here',
            });
        }

        // Belt and braces behind the status check above: a voucher only exists
        // once the accountant has approved, so this cannot fire on its own
        // today. It is here because "has anything been handed to Tally" is the
        // question that actually matters, and a future path that enqueues
        // earlier must not silently gain the right to rewrite a queued voucher
        // underneath the agent.
        if ($row->tally_sync_entry_id !== null || $entry->tallySyncEntries()->exists()) {
            throw new InvalidStatusTransitionException(
                'this batch is already on a Tally voucher — its figures can no longer be changed here',
            );
        }

        // A handover child opens from THIS segment's closing counts
        // (DayBinLedgerService::openingFor), so correcting a segment that has
        // already handed over would restate the next shift's opening
        // underneath it.
        if ($entry->childSegments()->exists()) {
            throw new InvalidStatusTransitionException(
                'this batch was handed over to the next shift, and that shift opened from its closing weights — it can no longer be corrected on its own',
            );
        }

        return $row;
    }

    /**
     * What has been done to this batch's figures since it was completed:
     * quality's returns (with the reason the floor has to read) and the
     * floor's own amendments.
     *
     * Always present, all-empty rather than null, so a client can render
     * "nothing to see" without telling a missing key apart from a null one —
     * the same rule qualityCheck() follows.
     *
     * @return array{
     *     awaiting_correction: bool, latest_return_reason: ?string,
     *     returns: list<array<string, mixed>>, amendments: list<array<string, mixed>>,
     * }
     */
    public function correctionHistory(ShiftProductionEntry $entry): array
    {
        $snapshot = $entry->config_snapshot ?? [];
        $returns = array_values(array_filter((array) ($snapshot['quality_returns'] ?? []), 'is_array'));
        $amendments = array_values(array_filter((array) ($snapshot['amendments'] ?? []), 'is_array'));
        $latest = $returns === [] ? null : $returns[count($returns) - 1];

        // How many returns the floor has already answered. Max rather than
        // "the last amendment's", so a plain typo-fix amendment recorded
        // before any return (answered_returns 0) can never pull the count back
        // down and re-strand a batch that was already corrected.
        $answered = 0;

        foreach ($amendments as $amendment) {
            $answered = max($answered, (int) ($amendment['answered_returns'] ?? 0));
        }

        return [
            // Quality sent this back AND THE FLOOR HAS NOT YET RE-SUBMITTED
            // IT: the flag the production queue needs to put the batch in
            // front of the supervisor again.
            //
            // The amendment count is load-bearing, not decoration. An
            // amendment leaves the batch in exactly the state a return leaves
            // it in — pending, completed, no quality check — and it does not
            // (and must not) clear quality_returns, which is the audit trail.
            // Without this test the flag would latch true for the life of the
            // batch, and the quality queue filters `awaiting_correction` OUT:
            // the corrected batch would never be offered for a check, so
            // quality_checked_at would never be set, so pmApprove's
            // precondition would never be met and the batch would sit outside
            // the approval chain with no route back into it.
            'awaiting_correction' => $latest !== null
                && count($returns) > $answered
                && $entry->quality_checked_at === null
                && $entry->status === ShiftProductionEntryStatus::Pending
                && $entry->batch_status === BatchStatus::Completed,
            'latest_return_reason' => $latest['reason'] ?? null,
            'returns' => $returns,
            'amendments' => $amendments,
        ];
    }

    /**
     * The one scrap item quality rejections are received against, or null.
     *
     * There is deliberately no fallback and no guessing. This ERP has no
     * scrap-item master and no colour → scrap-item mapping (the only
     * colour-driven resolver it has picks MASTERBATCH, and its own docblock
     * notes that a coloured non-masterbatch item — "an amber scrap, say" —
     * lands in the ambiguous pool rather than resolving). Picking "the item
     * whose name looks like scrap" would book real weight against a master
     * chosen by string matching, and the factory would discover it as a Tally
     * rejection days later. Null is the honest answer, and the caller records
     * the rejection and says so on the entry.
     *
     * SKU first, then exact name — a factory that mirrors Tally's "Pet Scrap"
     * as a plain item without a code can still name it. Soft-deleted items are
     * excluded by the model's global scope: a retired master must not silently
     * start receiving stock again.
     */
    private function resolveScrapItem(): ?Item
    {
        $configured = trim((string) (config('production.scrap.rejected_item_sku') ?? ''));

        if ($configured === '') {
            return null;
        }

        return Item::query()->where('sku', $configured)->first()
            ?? Item::query()->where('name', $configured)->first();
    }

    /**
     * The quality check as the approval screen needs to read it: the counts,
     * who certified them, and — the part that matters when the figures are
     * questioned — the BASIS on which a piece count became a weight.
     *
     * Returned even when no check has happened (all nulls, checked = false)
     * so a client can render "awaiting quality" without having to tell a
     * missing key apart from a null one.
     *
     * @return array<string, mixed>
     */
    public function qualityCheck(ShiftProductionEntry $entry): array
    {
        $checked = $entry->quality_checked_at !== null;
        $unitWeightGrams = $this->resolvedUnitWeightGrams($entry, $entry->item);
        $rejected = $entry->quality_rejected_nos;

        return [
            'checked' => $checked,
            'stage_enabled' => (bool) config('production.approvals.quality_stage_enabled', true),
            'reviewed_nos' => $entry->quality_reviewed_nos,
            'ok_nos' => $entry->quality_ok_nos,
            'rejected_nos' => $rejected,
            'checked_at' => $entry->quality_checked_at?->toIso8601String(),
            'note' => $entry->quality_note,
            // The supervisor's count and what production now stands at, side
            // by side — the subtraction the owner asked for, shown rather
            // than implied.
            'gross_quantity_produced' => $entry->gross_quantity_produced,
            'net_quantity_produced' => $entry->quantity_produced,
            // How the rejected pieces became kilograms. Named because this is
            // the figure that reduces the books, and "why is the rejection
            // 1.93 kg" must be answerable from the screen.
            'rejection_kg' => $checked && $rejected > 0 ? $entry->qc_rejection_kg : null,
            'rejection_kg_basis' => $checked && $rejected > 0
                ? [
                    'unit_weight_grams' => $unitWeightGrams,
                    'source' => $unitWeightGrams === null
                        ? null
                        : (($entry->config_snapshot['unit_weight_grams'] ?? null) ? 'run_snapshot' : 'item_master'),
                ]
                : null,
            // Null when the scrap receipt happened (or none was due); a
            // sentence when it was deliberately skipped.
            'scrap_note' => $entry->quality_scrap_note,
        ];
    }

    /**
     * The approval chain, each stage a blocking gate: Supervisor submits
     * (completeBatch → pending) → QUALITY checks and certifies the count →
     * Plant Manager verifies → Accountant reconciles and posts → Tally. Every
     * transition is the same conditional-UPDATE concurrency guard as the
     * batch lifecycle: two approvers acting at once can't double-advance.
     *
     * THE ACCOUNTANT IS FINAL. There is no MD stage — it was removed, and this
     * docblock described it for longer than the code did. Left uncorrected it
     * is the first thing anyone reads when debugging approvals under pressure.
     */
    public function pmApprove(ShiftProductionEntry $entry, int $signedBy): ShiftProductionEntry
    {
        // THE QUALITY GATE, enforced here rather than as a status of its own.
        // Adding a status between 'pending' and 'pm_approved' would have
        // rewritten every queue filter, report and screen that reads the
        // chain; making the check a PRECONDITION of this gate leaves all of
        // them untouched and puts the refusal where the person who needs to
        // read it is standing.
        //
        // Scoped to a row that is genuinely awaiting this gate: a call
        // against a wrong-status entry finds nothing here and falls through
        // to advance(), which reports the real problem (the transition)
        // instead of complaining about a quality check for an entry that was
        // never eligible anyway.
        if ((bool) config('production.approvals.quality_stage_enabled', true)) {
            $awaiting = ShiftProductionEntry::query()
                ->whereKey($entry->id)
                ->where('status', ShiftProductionEntryStatus::Pending->value)
                ->first(['id', 'quality_checked_at']);

            if ($awaiting !== null && $awaiting->quality_checked_at === null) {
                throw new InvalidStatusTransitionException(
                    'this batch is still in the quality queue — the quality check has to be recorded before it can be approved',
                );
            }
        }

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
     *
     * FOUR EYES, checked here first. Because this gate is the one that posts,
     * it is also the one that must not be reachable by the person who cleared
     * the gate before it — otherwise a single account signs a shift into the
     * books alone and the PM stage is decoration. Relaxable only by
     * production.approvals.allow_same_user (see config/production.php for why
     * there is deliberately no Administrator exemption: everyone who approves
     * here holds that role, so exempting it would leave the rule binding
     * nobody who could break it).
     */
    public function accountantApprove(ShiftProductionEntry $entry, int $signedBy): ShiftProductionEntry
    {
        // Read the PM's signature off the ROW, not the passed model: the
        // caller's copy was loaded before this request and a stale null here
        // would wave through exactly the case this refuses. Scoping the read
        // to status = pm_approved also means a wrong-status call finds null
        // and falls through to advance(), which reports the real problem
        // (the transition) instead of an identity complaint about an entry
        // that was never eligible anyway.
        $pmSignedBy = ShiftProductionEntry::query()
            ->whereKey($entry->id)
            ->where('status', ShiftProductionEntryStatus::PmApproved->value)
            ->value('plant_manager_signed_by');

        if (
            $pmSignedBy !== null
            && (int) $pmSignedBy === $signedBy
            && ! (bool) config('production.approvals.allow_same_user', false)
        ) {
            // Deliberately a plain sentence rather than ::make()'s
            // "Cannot transition X from Y to Z" — the person reading it is an
            // accountant being told why the button did nothing, and the
            // reason is not a status problem.
            throw new InvalidStatusTransitionException('the same person cannot give both approvals');
        }

        // THE POSTING GATE'S OWN PRECONDITION (config, default off — see
        // config/production.php 'require_postable_voucher' for why the
        // default is a safety property rather than a preference).
        //
        // The owner: "If the Tally preview is invalid, posting must remain
        // unavailable." This gate IS the posting moment, so the question is
        // asked here, of the EXISTING preview service — which builds its
        // payload with the same method the real post uses, so the gate can
        // never judge a voucher different from the one that would be sent.
        //
        // Scoped to a row genuinely awaiting this gate, exactly like the
        // four-eyes read above: a wrong-status call finds null here and falls
        // through to advance(), which reports the real problem (the
        // transition) rather than a voucher complaint about an entry that was
        // never eligible. A LOCAL- fixture is exempt because no voucher is
        // ever built for it (TallySyncService::isLocalFixtureEntry) — there
        // is no posting for a posting gate to protect, and refusing would
        // strand a real batch.
        if ((bool) config('production.approvals.require_postable_voucher', false)) {
            $awaiting = ShiftProductionEntry::query()
                ->whereKey($entry->id)
                ->where('status', ShiftProductionEntryStatus::PmApproved->value)
                ->first();

            if ($awaiting !== null && ! ($awaiting->item?->isLocalFixture() ?? false)) {
                $preview = $this->voucherPreview->forShiftProductionEntry($awaiting);

                if (! ($preview['postable'] ?? false)) {
                    $problems = array_merge(
                        $preview['problems'] ?? [],
                        ...array_map(fn ($line) => $line['problems'] ?? [], $preview['lines'] ?? []),
                    );

                    // Factory words, and the actual reasons — an accountant
                    // being told the button did nothing needs to know which
                    // master to fix, not that a boolean was false.
                    throw new InvalidStatusTransitionException(
                        'this batch cannot be posted to Tally yet, so it cannot be approved: '
                        .implode(' ', array_unique($problems))
                        .' Fix the masters (or the Production settings they name) and approve it again.',
                    );
                }
            }
        }

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
            // Who certified the count each later gate is signing off on.
            'qualityCheckedBy',
        ]);
    }

    /**
     * Withdraw a batch that should never have been started.
     *
     * This is NOT the undo button for production. It exists for exactly one
     * situation: somebody pressed Start Batch by mistake — a demo, a test, the
     * wrong machine — and the running batch now holds that machine, because
     * the start guard refuses a second batch while one is in progress. Before
     * this existed the only cure was editing the database by hand.
     *
     * WHY IT REFUSES SO MUCH. Everything a real batch touches is a fact
     * somewhere else: material left the day bin, the quality gate counted
     * bottles, a manager signed, cartons were labelled and possibly shipped, a
     * voucher went to Tally. Cancelling a batch that produced any of those
     * would orphan them — the batch would vanish from every queue while its
     * consequences stayed on the books, which is worse than the stuck machine
     * this method is here to fix. So the entry must be untouched in every one
     * of those respects, and the check is a refusal rather than a cleanup: this
     * method deliberately CANNOT reverse anything, so it may only ever run
     * where there is nothing to reverse.
     *
     * The row is kept, never deleted. Its number, machine, shift and start time
     * remain readable, with who cancelled it and why — a batch that disappeared
     * silently would be indistinguishable from data loss.
     *
     * @throws ValidationException when the batch is not running, or has any dependency
     */
    public function cancelTestBatch(ShiftProductionEntry $entry, ?int $cancelledBy, string $reason): ShiftProductionEntry
    {
        return DB::transaction(function () use ($entry, $cancelledBy, $reason) {
            // Re-read under a lock. Without it, a Complete landing between the
            // checks below and the write would be overwritten by the cancel —
            // a finished shift silently erased.
            $locked = ShiftProductionEntry::query()->lockForUpdate()->findOrFail($entry->id);

            $wasCompleted = $locked->batch_status === BatchStatus::Completed;

            if (! $wasCompleted && $locked->batch_status !== BatchStatus::InProgress) {
                throw ValidationException::withMessages([
                    'entry' => 'Only a running batch, or a completed batch quality has not yet checked, can be cancelled. This batch is '.$locked->batch_status->value.'.',
                ]);
            }

            // Each blocker names the thing that exists, because "cannot
            // cancel" without the reason sends someone to the database.
            $blockers = [];

            if ($locked->status !== ShiftProductionEntryStatus::Pending) {
                $blockers[] = 'it has already been through approval ('.$locked->status->value.')';
            }
            if ($locked->quality_checked_at !== null) {
                $blockers[] = 'quality has already checked it';
            }
            if ($locked->plant_manager_signed_at !== null) {
                $blockers[] = 'the plant manager has signed it';
            }
            if ($locked->accountant_signed_at !== null) {
                $blockers[] = 'accounts has signed it';
            }
            if ($locked->cartons()->exists()) {
                $blockers[] = 'carton labels have been generated';
            }
            if ($locked->tallySyncEntries()->exists()) {
                $blockers[] = 'a Tally voucher has been raised for it';
            }
            if ($locked->childSegments()->exists()) {
                $blockers[] = 'a shift handover created a following segment';
            }

            // Consumption and scrap are blockers on a RUNNING batch and not on
            // a completed one — the difference is whether there is a booking to
            // reverse. A completed batch's issues and finished-goods receipt
            // are exactly what reverseCompletionEffects() gives back; a running
            // batch has no completion to reverse, so material against it means
            // this is not the untouched mis-start the action is for.
            if (! $wasCompleted) {
                if ($locked->materialConsumptions()->exists()) {
                    $blockers[] = 'material has been consumed against it';
                }
                if ($locked->scraps()->exists()) {
                    $blockers[] = 'scrap has been recorded against it';
                }
            }

            // Day-bin movements are never reversed here. The day bin is a
            // physical place on the floor — resin that left it did leave it,
            // and no status change puts it back in the bin.
            if ($locked->dayBinMovements()->exists()) {
                $blockers[] = 'it has day-bin movements, which cancelling cannot put back';
            }

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'entry' => 'This batch cannot be cancelled because '.implode(', and ', $blockers).
                        '. Cancelling is only for a batch entered by mistake that quality and approval have not yet touched.',
                ]);
            }

            $previousBatchStatus = $locked->batch_status->value;
            $previousStatus = $locked->status->value;

            // The completion's own stock, given back inside THIS transaction:
            // each consumption line received at the unit cost its own issue
            // recorded, and the finished-goods receipt issued back out. Reused
            // rather than reimplemented — the amendment flow has run this exact
            // reversal against completed pre-quality batches all along, and a
            // second copy written here would be a second thing to keep correct.
            if ($wasCompleted) {
                $this->reverseCompletionEffects($entry, $locked, $cancelledBy);
            }

            $locked->forceFill([
                'batch_status' => BatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
                'cancellation_reason' => $reason,
                // The state it was cancelled FROM, and whether stock was given
                // back. A cancelled row's own batch_status no longer remembers
                // either, and "was this batch's stock reversed?" is the first
                // question anyone auditing it will ask.
                'config_snapshot' => array_merge($locked->config_snapshot ?? [], [
                    'cancellation' => [
                        'previous_batch_status' => $previousBatchStatus,
                        'previous_status' => $previousStatus,
                        'stock_reversed' => $wasCompleted,
                        'at' => now()->toIso8601String(),
                        'by' => $cancelledBy,
                        'reason' => $reason,
                    ],
                ]),
            ])->save();

            return $locked->fresh(['item', 'workCenter', 'shift', 'cancelledBy']);
        });
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
     * The norm is MACHINE THROUGHPUT — approved output plus QC-rejected plus
     * production-rejected pieces, at the run's frozen weight, plus lumps kg.
     * See expectedConsumptionKg() for why, and for what it was before.
     * A batch whose supervisor followed that formula lands on 0.0000 here.
     *
     * @return array{
     *     norm_source: ?string, norm_basis: ?string, expected_kg: ?string,
     *     actual_kg: string, variance_kg: ?string, variance_pct: ?float,
     *     rejection_kg: string, scrap_kg: string, unaccounted_kg: ?string,
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

        [$normSource, $normBasis, $expected] = $this->expectedConsumptionKg($entry);

        $variancePct = null;
        if ($expected !== null && bccomp($expected, '0', 4) !== 0) {
            $variancePct = round((float) bcmul(bcdiv(bcsub($actual, $expected, 8), $expected, 8), '100', 8), 1);
        }

        return [
            'norm_source' => $normSource,
            // The machine-readable tier stays 'bom'/'item_weight' — clients
            // and tests key off those strings. norm_basis is the sentence
            // the approver reads, and it is what changed: the norm is no
            // longer "what came out", it is what went through.
            'norm_basis' => $normBasis,
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
            // Deliberately EQUAL to variance_kg now, and kept as its own key
            // only because the shape is on the wire. It used to subtract
            // rejection and scrap from the gap because the norm ignored
            // them; the norm covers both today, so subtracting them again
            // would count the same kilograms twice and report a loss as a
            // surplus. What the norm cannot explain is simply what is left.
            'unaccounted_kg' => $expected !== null ? bcsub($actual, $expected, 4) : null,
        ];
    }

    /**
     * What the batch's consumed material actually COST — each line priced at
     * the unit cost its own stock ISSUE movement recorded at the moment of
     * issue (the moving-average cost then, stamped by recordIssue), never at
     * today's average. Pure read, no writes. Null for a batch that hasn't
     * completed (nothing has been issued yet).
     *
     * Matching: completeBatch stamps every movement of one entry with
     * reference "SPE #{id}", shared by the consumption issues AND the FG
     * receipt — so lines match on reference + type=issue + item + warehouse.
     * Duplicate (item, warehouse) consumption lines pair off against the
     * issue movements one each, so nothing is counted twice.
     *
     * NEWEST FIRST within a pool, because an AMENDED batch has more than one
     * completion's issues under that one reference (the ledger is append-only,
     * so the wrong completion's issues and their reversals both stay). The
     * live consumption lines are the latest completion's, and pairing from the
     * front would have priced them against the superseded run's costs. Within
     * a single completion the two orders are identical: an issue does not move
     * the average cost, so every issue of one item at one warehouse in the
     * same completion carries the same unit cost.
     *
     * Null-safety rule: a line whose movement is missing, whose movement
     * carries no cost, or which was issued out of a bin that had no recorded
     * stock (and so no recorded cost) prices at null — and total_cost is
     * null whenever ANY line is unpriced. A total that silently omitted a
     * line would read as "the batch cost this much" while understating it;
     * no price is ever guessed.
     *
     * @return array{
     *     lines: list<array{item_id: int, item_name: ?string, warehouse_id: int,
     *         quantity_issued_kg: string, unit_cost: ?string, cost: ?string}>,
     *     total_cost: ?string,
     * }|null
     */
    public function materialCost(ShiftProductionEntry $entry): ?array
    {
        if ($entry->batch_status !== BatchStatus::Completed) {
            return null;
        }

        // withTrashed for the same reason as consumptionVariance(): a master
        // cleanup that soft-deletes a material must not blank its name here.
        $entry->loadMissing([
            'materialConsumptions.item' => fn ($query) => $query->withTrashed(),
        ]);

        // One pool per (item, warehouse); each line shift()s its own movement.
        $pool = $this->stock->issuesForReference("SPE #{$entry->id}")
            ->groupBy(fn ($movement) => "{$movement->item_id}@{$movement->warehouse_id}");

        // ZERO IS NOT A PRICE. A bin holding no recorded stock holds no
        // recorded average cost either, so an issue that ran it negative
        // (see config/production.php 'stock') stamps unit_cost 0.0000 — the
        // ABSENCE of a price, which the rule above says is never guessed.
        // Left alone, this batch would tell the accountant that 118.998 kg
        // of resin cost nothing, on the same screen as the banner asking
        // them to go fix that material's stock.
        //
        // Keyed off the recorded shortfall, so only those lines are
        // affected: a genuinely free item has no shortfall record and still
        // prices at zero, as it should.
        $unpriced = [];
        foreach ($this->stockShortfalls($entry) as $short) {
            $unpriced["{$short['item_id']}@{$short['warehouse_id']}"] = true;
        }

        $lines = [];
        $total = '0.0000';
        $everyLinePriced = true;

        foreach ($entry->materialConsumptions as $consumption) {
            $key = "{$consumption->item_id}@{$consumption->warehouse_id}";
            $movement = $pool->get($key)?->pop();
            $unitCost = $movement?->unit_cost;

            if ($unitCost !== null && isset($unpriced[$key]) && bccomp((string) $unitCost, '0', 4) === 0) {
                $unitCost = null;
            }

            $cost = $unitCost !== null
                ? bcmul((string) $consumption->quantity_issued_kg, (string) $unitCost, 4)
                : null;

            $lines[] = [
                'item_id' => $consumption->item_id,
                'item_name' => $consumption->item?->name,
                'warehouse_id' => $consumption->warehouse_id,
                'quantity_issued_kg' => (string) $consumption->quantity_issued_kg,
                'unit_cost' => $unitCost !== null ? (string) $unitCost : null,
                'cost' => $cost,
            ];

            if ($cost === null) {
                $everyLinePriced = false;
            } else {
                $total = bcadd($total, $cost, 4);
            }
        }

        return [
            'lines' => $lines,
            'total_cost' => $everyLinePriced ? $total : null,
        ];
    }

    /**
     * The expected-output engine (docs/archive/SHIFT-REDESIGN-FORMULAS.md #22-24 and the
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
     *     stock_shortfalls: list<array{item_id: ?int, item_name: ?string,
     *         warehouse_id: ?int, warehouse_name: ?string, short_kg: string,
     *         item_uom: ?string}>,
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
        //
        // 'over_standard' is tested FIRST and outranks ok/watch/investigate:
        // a run above its own standard would otherwise show as the greenest
        // possible 'ok', which is the opposite of the truth. Owner, 30-Jul:
        // "the efficiency should not go more than 100%. if a machine can
        // produce a certain [amount] of material how can it be more than
        // that". Over 100% means an input is wrong — produced count, running
        // hours, cavities, or a standard cycle time set slower than the
        // machine really runs (correctable on Product Standards / Machine
        // Exceptions). It is a WARNING, never a gate: blocks_approval below
        // keys only off unaccounted_blocking_kg and must stay that way — the
        // pieces were genuinely made and the shift must still be recordable.
        //
        // Strict `>` so exactly 100.0 is 'ok', not over: the boundary is the
        // standard being met, not beaten.
        $efficiencyBand = null;
        if ($efficiency !== null) {
            $efficiencyBand = $efficiency > $tolerances['efficiency_over'] ? 'over_standard'
                : ($efficiency >= $tolerances['efficiency_ok'] ? 'ok'
                : ($efficiency >= $tolerances['efficiency_watch'] ? 'watch' : 'investigate'));
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
            // Material this batch issued that the ledger did not have.
            // Deliberately reported NEXT TO blocks_approval and deliberately
            // absent FROM it: the shift is the truth, the stock record is
            // the thing that needs fixing, and the accountant is the person
            // who can fix it. See config/production.php 'stock'.
            'stock_shortfalls' => $this->stockShortfalls($entry),
        ];
    }

    /**
     * The shortfalls completeBatch froze onto this entry, read straight back
     * off its snapshot — never recomputed from today's balances, which have
     * moved on since (and, if the accountant has done their job, no longer
     * show a gap at all).
     *
     * Empty list, never null: a screen must be able to say "no shortfall"
     * without distinguishing that from "this field is missing".
     *
     * @return list<array{item_id: ?int, item_name: ?string, item_uom: ?string,
     *     warehouse_id: ?int, warehouse_name: ?string, short_kg: string}>
     */
    private function stockShortfalls(ShiftProductionEntry $entry): array
    {
        $recorded = $entry->config_snapshot['stock_shortfalls'] ?? [];

        if (! is_array($recorded)) {
            return [];
        }

        return array_values(array_map(fn (array $line) => [
            'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
            'item_name' => $line['item_name'] ?? null,
            // Absent on a snapshot written before the unit was frozen. Null
            // rather than a defaulted 'kg': the screen prints no unit at all
            // there, which is honest, where "kg" beside a carton count is not.
            'item_uom' => $line['item_uom'] ?? null,
            'warehouse_id' => isset($line['warehouse_id']) ? (int) $line['warehouse_id'] : null,
            'warehouse_name' => $line['warehouse_name'] ?? null,
            'short_kg' => (string) ($line['short_kg'] ?? '0'),
        ], array_filter($recorded, 'is_array')));
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

    /**
     * Resolve the consumption norm: [norm_source, norm_basis, expected_kg].
     * A lazy per-entry BOM lookup — approval lists are small pages, so this
     * stays simpler than a batch preload.
     *
     * THE NORM IS MACHINE THROUGHPUT, NOT APPROVED OUTPUT. Every piece the
     * machine moulded was moulded out of the same resin, whoever later
     * refused it, so the norm counts all three fates plus the resin that
     * never became a piece at all:
     *
     *     (approved output + QC-rejected pieces + production-rejected pieces)
     *         × the run's frozen unit weight  +  lumps kg
     *
     * which is the factory's own arithmetic, and on the weight tier below is
     * textually the pieces side of refuseStaleMaterialLines() with the QC
     * term added (that path runs with the QC columns cleared, so it has no
     * third fate to count). The two must not drift apart: the guard would
     * then refuse a correction whose figures this norm calls perfect.
     *
     * Netting the norm to approved output only — what it did until this was
     * fixed — charged the supervisor for resin they never lost. A batch that
     * followed the formula exactly, with real rejects and real lumps, read
     * as a +3.6% "Watch" on the approval screen and there was nothing to
     * find. Now such a batch reads 0.0000 and the bands fire only on a
     * genuine hand-override of the kilograms.
     *
     * BEFORE AND AFTER THE QUALITY CHECK BOTH WORK, without a branch. The
     * check nets quantity_produced down and files the count it removed in
     * quality_rejected_nos, so net + QC-rejected is the packed count either
     * way; before a check quality_rejected_nos is null and quantity_produced
     * IS the packed count. So the check moves approved FG and never moves
     * this norm — which is the point: QC rejects are inside the packed count
     * and were never extra resin.
     *
     * Both tiers norm on throughput. A kg-type BOM line is a per-piece rate
     * like the weight is; leaving the BOM tier on net output would keep this
     * exact defect alive for every product that has one.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function expectedConsumptionKg(ShiftProductionEntry $entry): array
    {
        // Nulls coalesce BEFORE the cast — bcadd('') is a fatal, and an
        // unchecked batch has null in quality_rejected_nos by definition.
        $throughput = bcadd(
            bcadd(
                (string) ($entry->quantity_produced ?? '0'),
                (string) ($entry->quality_rejected_nos ?? '0'),
                4,
            ),
            (string) ($entry->quantity_scrap ?? '0'),
            4,
        );

        // Lumps are weighed, not counted, so they join the norm in kg rather
        // than through the unit weight — and they belong to it even on a
        // batch whose piece counts are all zero.
        $lumps = $this->lumpsKgOfScraps($entry->scraps);

        $hasOutput = bccomp($throughput, '0', 4) !== 0 || bccomp($lumps, '0', 4) !== 0;

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
                return [
                    'bom',
                    'throughput at BOM rate + lumps',
                    $hasOutput ? bcadd(bcmul($throughput, $kgPerUnit, 4), $lumps, 4) : null,
                ];
            }
        }

        // Same weight the run's own kg figures were computed at — the frozen
        // snapshot first, the item column only for legacy rows (see
        // resolvedUnitWeightGrams). A norm sourced from a master the run
        // never used would put a phantom variance on every Tally-synced
        // product. The norm_source label stays 'item_weight': it names the
        // TIER (a per-piece weight rather than a BOM), and every client and
        // test already reads that string.
        $weightGrams = $this->resolvedUnitWeightGrams($entry, $entry->item);
        if ($weightGrams !== null) {
            return [
                'item_weight',
                'throughput at standard weight + lumps',
                $hasOutput
                    ? bcadd(bcdiv(bcmul($throughput, $weightGrams, 4), '1000', 4), $lumps, 4)
                    : null,
            ];
        }

        return [null, null, null];
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

    /**
     * Pieces → kg at a unit weight already resolved by
     * resolvedUnitWeightGrams(). No weight means no kg: null, never 0 — a
     * zero would report a batch that produced nothing and reconcile every
     * issued kilo as unaccounted.
     *
     * The arithmetic (4dp multiply, then ÷1000 at 4dp) is deliberately left
     * exactly as it was — ProductionCalculationEngine::piecesToKg() carries
     * the workbook's own 8dp variant and the two must not be merged
     * casually; every figure pinned in the suite hangs off this one.
     */
    private function toKg(string $quantityNos, ?string $unitWeightGrams): ?string
    {
        if ($unitWeightGrams === null) {
            return null;
        }

        return bcdiv(bcmul($quantityNos, $unitWeightGrams, 4), '1000', 4);
    }

    /**
     * The unit weight THIS RUN resolved, in grams.
     *
     * The entry's frozen config_snapshot first — Start Batch already decided
     * the effective weight there (configuration → standard → item master) and
     * froze it, so this is by definition the weight the run was quoted and
     * measured against. The item master's own nominal_weight_grams is only a
     * fallback, for legacy rows whose snapshot predates the key.
     *
     * Why the order matters on the live floor: nothing WRITES
     * items.nominal_weight_grams for a Tally-synced master (only item CRUD
     * and the seeders do), so real products carry a weight on their approved
     * standard/configuration and nothing on the item. Reading the column
     * first left quantity_produced_kg null for every one of them, and with it
     * rejection kg, unaccounted kg and the accountant's variance banner.
     *
     * The snapshot stores the weight as a string and writes '' when nothing
     * resolved at all, so blanks are rejected before any bcmath call — and a
     * zero or negative weight is "no weight", not a weight of zero.
     */
    private function resolvedUnitWeightGrams(ShiftProductionEntry $entry, ?Item $item): ?string
    {
        // One implementation, on the model — the carton label resource reads
        // the same figure, and two copies of this rule is how a screen ends
        // up showing a weight the server never computed with.
        return $entry->resolvedUnitWeightGrams($item);
    }
}
