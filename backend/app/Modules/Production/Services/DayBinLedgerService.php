<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\DayBinBalanceException;
use App\Modules\Production\Exceptions\SegmentClosedException;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The machine-side half of Phase 6 traceability: owns day_bin_movements
 * (the per-machine per-material ledger) and every formula over it. The
 * bag-side half (lots/bags, remaining_kg, FIFO) lives in Inventory's
 * TraceabilityService, which calls into here — cross-module writes go
 * through services, never through the other module's models.
 *
 * The consumption formula (design doc, verbatim):
 *   actual_consumed_kg = opening_day_bin + Σ loaded − closing_day_bin − Σ returned
 * per (work_center, item, segment window), where opening is the previous
 * segment's closing count (0 for a fresh run) and closing is this
 * segment's latest count. All arithmetic is bcmath at 4dp, matching the
 * rest of the shift engine.
 */
class DayBinLedgerService
{
    /**
     * Append one ledger row, enforcing the no-negative-balance guards.
     * `recorded_at` defaults to now; guards:
     *  - return: quantity ≤ current bin balance;
     *  - count: quantity ≤ opening + loaded − returned (segment window
     *    when a segment is given, running balance otherwise).
     *
     * work_center_id IS NULLABLE, and null means the COMMON RESIN INPUT — the
     * one loading point serving every machine (owner's correction, 2-Aug).
     * The legacy per-machine bin-bay path still passes a machine and its
     * guards are unchanged; the common-input scan passes null and its kg are
     * summed factory-wide by FactoryDayBinService::commonResinEstimate.
     * Return and Count still require a machine — they are per-machine
     * questions and their balance guards are per-machine sums.
     *
     * @param  array{
     *     work_center_id: ?int, item_id: int, type: string,
     *     quantity_kg: string|float|int, shift_production_entry_id?: ?int,
     *     material_bag_id?: ?int, recorded_by?: ?int, recorded_at?: mixed,
     * }  $data
     */
    public function record(array $data): DayBinMovement
    {
        return DB::transaction(function () use ($data) {
            $type = DayBinMovementType::from($data['type']);
            $quantity = bcadd((string) $data['quantity_kg'], '0', 4);
            $workCenterId = isset($data['work_center_id']) ? (int) $data['work_center_id'] : null;
            $itemId = (int) $data['item_id'];
            $entryId = $data['shift_production_entry_id'] ?? null;

            // Serialize the balance-guarded writers per machine: two
            // concurrent returns (or counts) must not both read the same
            // balance and both pass. The work-center row is the natural
            // mutex — every writer for one machine funnels through it, and
            // the lock lives exactly as long as this transaction.
            if ($workCenterId !== null
                && in_array($type, [DayBinMovementType::Return, DayBinMovementType::Count], true)) {
                WorkCenter::query()->whereKey($workCenterId)->lockForUpdate()->first();
            }

            // Segment window guard: a completed segment's consumption is
            // already submitted — nothing may be back-recorded into it.
            $segment = $entryId !== null ? ShiftProductionEntry::query()->find($entryId) : null;
            if ($segment !== null && $segment->batch_status !== BatchStatus::InProgress) {
                throw SegmentClosedException::make($segment->id);
            }

            // Both guards are PER-MACHINE sums, so both need a machine. A
            // machineless Return or Count is not a case this ledger can
            // judge and is refused rather than waved through with an
            // unguarded write — the common input's own question is answered
            // by FactoryDayBinService::commonResinEstimate, not here.
            if (in_array($type, [DayBinMovementType::Return, DayBinMovementType::Count], true)
                && $workCenterId === null) {
                throw ValidationException::withMessages([
                    'work_center_id' => 'Name the machine this day-bin '.$type->value.' belongs to.',
                ]);
            }

            if ($type === DayBinMovementType::Return) {
                $balance = $this->balanceFor($workCenterId, $itemId);
                if (bccomp($quantity, $balance, 4) === 1) {
                    throw DayBinBalanceException::forReturn($quantity, $balance);
                }
            }

            if ($type === DayBinMovementType::Count) {
                $maximum = $segment !== null
                    ? $this->segmentHeadroom($segment, $itemId)
                    : $this->balanceFor($workCenterId, $itemId);

                if (bccomp($quantity, $maximum, 4) === 1) {
                    throw DayBinBalanceException::forCount($quantity, $maximum);
                }
            }

            return DayBinMovement::create([
                'work_center_id' => $workCenterId,
                'item_id' => $itemId,
                'shift_production_entry_id' => $entryId,
                'type' => $type,
                'material_bag_id' => $data['material_bag_id'] ?? null,
                'quantity_kg' => $quantity,
                // Passed through rather than interpreted: the decision about
                // WHEN an acknowledgement is required lives at the scan
                // (FactoryDayBinService::loadBag), because only that path
                // knows the machine's estimate. This ledger simply records
                // what it is handed.
                'balance_ack_reason' => $data['balance_ack_reason'] ?? null,
                'balance_ack_note' => $data['balance_ack_note'] ?? null,
                'recorded_by' => $data['recorded_by'] ?? null,
                'recorded_at' => $data['recorded_at'] ?? now(),
            ]);
        });
    }

    public function paginate(?int $workCenterId = null, ?int $itemId = null, int $perPage = 20): LengthAwarePaginator
    {
        return DayBinMovement::query()
            ->with(['workCenter', 'item', 'materialBag', 'recordedBy'])
            ->when($workCenterId, fn ($query) => $query->where('work_center_id', $workCenterId))
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Running physical estimate of the bin: the latest count (an absolute
     * observation, which re-anchors the figure) plus loads minus returns
     * recorded after it. Counts are observations, not deltas — that is why
     * they reset rather than sum.
     */
    public function balanceFor(int $workCenterId, int $itemId): string
    {
        $anchor = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('type', DayBinMovementType::Count->value)
            ->orderByDesc('id')
            ->first();

        $balance = $anchor !== null ? bcadd((string) $anchor->quantity_kg, '0', 4) : '0.0000';

        $subsequent = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->when($anchor, fn ($query) => $query->where('id', '>', $anchor->id))
            ->whereIn('type', [DayBinMovementType::Load->value, DayBinMovementType::Return->value])
            ->get();

        foreach ($subsequent as $movement) {
            $balance = $movement->type === DayBinMovementType::Load
                ? bcadd($balance, (string) $movement->quantity_kg, 4)
                : bcsub($balance, (string) $movement->quantity_kg, 4);
        }

        return $balance;
    }

    /**
     * opening_day_bin for a segment: the previous segment's closing count,
     * 0 for a fresh run (design formula). A parent that recorded no
     * closing count contributes 0 — the factory can ignore counting
     * entirely and nothing breaks.
     */
    public function openingFor(ShiftProductionEntry $segment, int $itemId): string
    {
        // A handover child inherits the outgoing segment's counted closing —
        // that IS the physical handover, and it stays authoritative.
        if ($segment->parent_entry_id !== null) {
            $parent = ShiftProductionEntry::query()->find($segment->parent_entry_id);

            return $parent !== null ? ($this->closingFor($parent, $itemId) ?? '0.0000') : '0.0000';
        }

        // No parent does NOT mean an empty bin. A machine whose previous
        // batch completed normally usually still holds material, and
        // returning 0 here silently understated consumption by exactly that
        // carry-over: consumed = opening + loaded - closing - returned, so a
        // missing opening is subtracted from the answer, with no error and
        // no flag. It also made segmentHeadroom() refuse an honest closing
        // count that merely reflected the leftover.
        //
        // The truthful opening is what the ledger already held for this
        // machine and material immediately BEFORE this segment's own first
        // movement.
        return $this->balanceBeforeSegment($segment, $itemId);
    }

    /**
     * Bin balance for a machine+material as at the moment a segment began —
     * every movement recorded before this segment's first one.
     *
     * Same anchor rule as balanceFor(): a count is an absolute observation
     * that re-anchors the figure, loads and returns after it are deltas.
     */
    private function balanceBeforeSegment(ShiftProductionEntry $segment, int $itemId): string
    {
        $firstOwn = DayBinMovement::query()
            ->where('shift_production_entry_id', $segment->id)
            ->where('item_id', $itemId)
            ->orderBy('id')
            ->first();

        $priorScope = fn ($query) => $query
            ->where('work_center_id', $segment->work_center_id)
            ->where('item_id', $itemId)
            // Movements this segment has not yet made, and any belonging to
            // it, are excluded — this is the state it inherited.
            ->when($firstOwn, fn ($q) => $q->where('id', '<', $firstOwn->id))
            ->where(fn ($q) => $q->whereNull('shift_production_entry_id')
                ->orWhere('shift_production_entry_id', '!=', $segment->id));

        $anchor = DayBinMovement::query()
            ->tap($priorScope)
            ->where('type', DayBinMovementType::Count->value)
            ->orderByDesc('id')
            ->first();

        $balance = $anchor !== null ? bcadd((string) $anchor->quantity_kg, '0', 4) : '0.0000';

        $subsequent = DayBinMovement::query()
            ->tap($priorScope)
            ->when($anchor, fn ($query) => $query->where('id', '>', $anchor->id))
            ->whereIn('type', [DayBinMovementType::Load->value, DayBinMovementType::Return->value])
            ->get();

        foreach ($subsequent as $movement) {
            $balance = $movement->type === DayBinMovementType::Load
                ? bcadd($balance, (string) $movement->quantity_kg, 4)
                : bcsub($balance, (string) $movement->quantity_kg, 4);
        }

        return bccomp($balance, '0', 4) === -1 ? '0.0000' : $balance;
    }

    /**
     * closing_day_bin for a segment: its latest count movement, null when
     * no count was recorded (consumption is then not computable).
     */
    public function closingFor(ShiftProductionEntry $segment, int $itemId): ?string
    {
        $count = DayBinMovement::query()
            ->where('shift_production_entry_id', $segment->id)
            ->where('item_id', $itemId)
            ->where('type', DayBinMovementType::Count->value)
            ->orderByDesc('id')
            ->first();

        return $count !== null ? bcadd((string) $count->quantity_kg, '0', 4) : null;
    }

    /**
     * The design formula, all terms surfaced so the Complete Batch screen
     * can show the working beside the prefilled figure:
     * consumed = opening + loaded − closing − returned; null consumed when
     * no closing count exists yet.
     *
     * @return array{
     *     opening_kg: string, loaded_kg: string, returned_kg: string,
     *     closing_kg: ?string, consumed_kg: ?string,
     * }
     */
    public function consumptionFor(ShiftProductionEntry $segment, int $itemId): array
    {
        $opening = $this->openingFor($segment, $itemId);
        $loaded = $this->sumFor($segment, $itemId, DayBinMovementType::Load);
        $returned = $this->sumFor($segment, $itemId, DayBinMovementType::Return);
        $closing = $this->closingFor($segment, $itemId);

        return [
            'opening_kg' => $opening,
            'loaded_kg' => $loaded,
            'returned_kg' => $returned,
            'closing_kg' => $closing,
            'consumed_kg' => $closing !== null
                ? bcsub(bcsub(bcadd($opening, $loaded, 4), $closing, 4), $returned, 4)
                : null,
        ];
    }

    /**
     * Id-based variant for cross-module callers (Inventory's
     * TraceabilityService) — the segment model stays this module's.
     *
     * @return array{
     *     opening_kg: string, loaded_kg: string, returned_kg: string,
     *     closing_kg: ?string, consumed_kg: ?string,
     * }
     */
    public function consumptionForEntryId(int $shiftProductionEntryId, int $itemId): array
    {
        return $this->consumptionFor(
            ShiftProductionEntry::query()->findOrFail($shiftProductionEntryId),
            $itemId,
        );
    }

    /**
     * Every item that has ever moved through one machine's day bin —
     * the material axis of the per-machine aggregate state.
     *
     * @return array<int, int>
     */
    public function itemIdsWithMovements(int $workCenterId): array
    {
        return DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->distinct()
            ->orderBy('item_id')
            ->pluck('item_id')
            ->all();
    }

    /**
     * The per-entry (segment) day-bin summary the Complete Batch screen
     * consumes: one row per material that moved in this segment, each
     * carrying the full formula working. `consumption_kg` is null until a
     * closing count exists (not computable ≠ zero).
     *
     * @return array{
     *     has_movements: bool,
     *     materials: array<int, array{
     *         item: array{id: int, name: string, sku: ?string},
     *         opening_kg: string, loaded_kg: string, returned_kg: string,
     *         closing_kg: ?string, consumption_kg: ?string,
     *     }>,
     * }
     */
    public function entrySummaryFor(ShiftProductionEntry $segment): array
    {
        $items = DayBinMovement::query()
            ->where('shift_production_entry_id', $segment->id)
            ->with('item')
            ->get()
            ->pluck('item')
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values();

        $materials = [];
        foreach ($items as $item) {
            $consumption = $this->consumptionFor($segment, $item->id);

            $materials[] = [
                'item' => ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku],
                'opening_kg' => $consumption['opening_kg'],
                'loaded_kg' => $consumption['loaded_kg'],
                'returned_kg' => $consumption['returned_kg'],
                'closing_kg' => $consumption['closing_kg'],
                'consumption_kg' => $consumption['consumed_kg'],
            ];
        }

        return [
            'has_movements' => $items->isNotEmpty(),
            'materials' => $materials,
        ];
    }

    /**
     * The count ceiling for a segment window: opening + loaded − returned.
     * (A closing count above this would mean material appeared from
     * nowhere.)
     */
    private function segmentHeadroom(ShiftProductionEntry $segment, int $itemId): string
    {
        return bcsub(
            bcadd(
                $this->openingFor($segment, $itemId),
                $this->sumFor($segment, $itemId, DayBinMovementType::Load),
                4,
            ),
            $this->sumFor($segment, $itemId, DayBinMovementType::Return),
            4,
        );
    }

    private function sumFor(ShiftProductionEntry $segment, int $itemId, DayBinMovementType $type): string
    {
        // bcmath accumulation rather than SQL SUM: SQLite hands decimal
        // sums back as floats, and the 4dp string discipline must hold.
        $total = '0.0000';

        $movements = DayBinMovement::query()
            ->where('shift_production_entry_id', $segment->id)
            ->where('item_id', $itemId)
            ->where('type', $type->value)
            ->get();

        foreach ($movements as $movement) {
            $total = bcadd($total, (string) $movement->quantity_kg, 4);
        }

        return $total;
    }
}
