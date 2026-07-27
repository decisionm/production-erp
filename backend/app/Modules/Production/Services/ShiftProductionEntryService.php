<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    ) {}

    public function paginate(int $perPage = 20, ?ShiftProductionEntryStatus $status = null): LengthAwarePaginator
    {
        return ShiftProductionEntry::query()
            ->with([
                'shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator',
                'materialConsumptions.item', 'scraps.scrapReason', 'approvedBy',
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
     * @param  array{shift_id: int, work_center_id: int, item_id: int, warehouse_id: int, production_date?: string, operator_id?: int}  $data
     */
    public function startBatch(array $data, ?int $createdBy): ShiftProductionEntry
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // A machine can only physically run one item at a time — reject
            // a second "Start Batch" if this machine already has one
            // in_progress, per PRODUCTION-SUPERVISOR-UX-PLAN.md §2 ("two
            // people can genuinely tap the same machine at once"). The row
            // lock only closes the window between two transactions that
            // both reach this check; it can't lock a row that doesn't exist
            // yet, so a lock on `work_centers` itself narrows the race to
            // effectively zero for how this is actually used.
            $alreadyRunning = ShiftProductionEntry::query()
                ->where('work_center_id', $data['work_center_id'])
                ->where('batch_status', BatchStatus::InProgress->value)
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw InvalidStatusTransitionException::make(
                    'shift production entry batch',
                    'idle',
                    BatchStatus::InProgress->value,
                );
            }

            $entry = ShiftProductionEntry::create([
                'shift_id' => $data['shift_id'],
                'work_center_id' => $data['work_center_id'],
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'production_date' => $data['production_date'] ?? Shift::query()->find($data['shift_id'])?->productionDateFor() ?? now()->toDateString(),
                'batch_status' => BatchStatus::InProgress,
                'quantity_produced' => null,
                'quantity_scrap' => '0',
                'operator_id' => $data['operator_id'] ?? null,
                'created_by' => $createdBy,
            ]);

            return $entry->fresh(['shift', 'workCenter', 'item', 'warehouse', 'operator']);
        });
    }

    /**
     * @param  array{
     *     batch_number?: string, quantity_produced: string, quantity_scrap?: string, scrap_reason_id?: int,
     *     nos_per_tray?: int, no_of_trays?: int, nos_per_box?: int, no_of_box?: int, notes?: string,
     *     material_consumptions?: array<int, array{item_id: int, warehouse_id: int, quantity_issued_kg: string}>,
     *     scraps?: array<int, array{type: string, quantity_nos?: string, quantity_kg?: string, scrap_reason_id?: int}>,
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
                    'batch_number' => $data['batch_number'] ?? null,
                    'quantity_produced' => $data['quantity_produced'],
                    'quantity_produced_kg' => $quantityProducedKg,
                    'quantity_scrap' => $data['quantity_scrap'] ?? '0',
                    'quantity_rejection_kg' => $quantityRejectionKg,
                    'scrap_reason_id' => $data['scrap_reason_id'] ?? null,
                    'nos_per_tray' => $data['nos_per_tray'] ?? null,
                    'no_of_trays' => $data['no_of_trays'] ?? null,
                    'nos_per_box' => $data['nos_per_box'] ?? null,
                    'no_of_box' => $data['no_of_box'] ?? null,
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
                'materialConsumptions.item', 'scraps.scrapReason',
            ]);
        });
    }

    /**
     * The 4-stage approval chain (factory answer 9), each stage a blocking
     * gate: Supervisor submits (completeBatch → pending) → Plant Manager
     * verifies → Accountant reconciles → MD final approval → Tally. Every
     * transition is the same conditional-UPDATE concurrency guard as the
     * batch lifecycle: two approvers acting at once can't double-advance.
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

    /**
     * DORMANT: reserved for a future "big approvals" flow (e.g. value-
     * threshold entries routed MD-ward before posting). The normal path goes
     * pm_approved → approved at the accountant, so accountant_approved is
     * currently unreachable; this transition stays for when thresholds land.
     */
    public function mdApprove(ShiftProductionEntry $entry, int $approvedBy): ShiftProductionEntry
    {
        $fresh = $this->advance($entry, ShiftProductionEntryStatus::AccountantApproved, ShiftProductionEntryStatus::Approved, [
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

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
        $entry->loadMissing(['item', 'materialConsumptions', 'scraps']);

        $actual = '0';
        foreach ($entry->materialConsumptions as $consumption) {
            $actual = bcadd($actual, (string) $consumption->quantity_issued_kg, 4);
        }

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
            'rejection_kg' => $rejection,
            'scrap_kg' => $scrap,
            'unaccounted_kg' => $expected !== null
                ? bcsub(bcsub(bcsub($actual, $expected, 4), $rejection, 4), $scrap, 4)
                : null,
        ];
    }

    /**
     * Resolve the consumption norm: [norm_source, expected_kg]. A lazy
     * per-entry BOM lookup — approval lists are small pages, so this stays
     * simpler than a batch preload.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function expectedConsumptionKg(ShiftProductionEntry $entry): array
    {
        $produced = $entry->quantity_produced !== null ? (string) $entry->quantity_produced : null;
        $hasProduced = $produced !== null && bccomp($produced, '0', 4) !== 0;

        if ($bom = $this->activeBomFor($entry->item_id)) {
            // Soft-deleted component masters still carry their UOM — this is
            // a read-only norm, so a trashed resin line must not zero it.
            $bom->load(['lines.component' => fn ($query) => $query->withTrashed()]);

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

    private function isMassUom(?string $uom): bool
    {
        return in_array(strtolower(trim((string) $uom)), ['kg', 'kgs', 'kilogram', 'kilograms'], true);
    }

    private function toKg(string $quantityNos, ?Item $item): ?string
    {
        if ($item === null || $item->nominal_weight_grams === null) {
            return null;
        }

        return bcdiv(bcmul($quantityNos, (string) $item->nominal_weight_grams, 4), '1000', 4);
    }
}
