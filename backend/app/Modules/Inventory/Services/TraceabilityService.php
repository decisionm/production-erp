<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\BagOverloadException;
use App\Modules\Inventory\Exceptions\FifoPolicyException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\DayBinLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The store-side half of Phase 6 traceability: supplier lots, bag fan-out
 * with unique barcodes, bag remaining_kg accounting, and the FIFO policy.
 * Every day-bin ledger row it produces goes through Production's
 * DayBinLedgerService (cross-module via service, per the module pattern) —
 * one atomic transaction covers the bag decrement AND the ledger append,
 * so the two can never drift.
 *
 * Vincent's open questions are deliberately NOT decided here:
 *  Q1 bag sizes/supplier barcodes — per-lot data + optional barcode input;
 *  Q2/Q6 weighing method — counts and partial loads accept any weighed
 *     figure, the service never derives one;
 *  Q3 FIFO policy — config production.traceability.fifo_enforced +
 *     permission;
 *  Q4 return destination — returns update the bag only when one is named,
 *     a bagless return is just a ledger row (regrind flow can attach later);
 *  Q5 handover ownership — whoever is authenticated records it.
 */
class TraceabilityService
{
    public function __construct(private readonly DayBinLedgerService $dayBin) {}

    public function paginateLots(?int $itemId = null, ?int $grnId = null, int $perPage = 20): LengthAwarePaginator
    {
        return MaterialLot::query()
            // grn + goodsReceiptLine: the register shows each lot's receipt,
            // its price and the date+time it was received (read-only).
            ->with(['item', 'bags', 'grn', 'goodsReceiptLine'])
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($grnId, fn ($query) => $query->where('grn_id', $grnId))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function loadLot(MaterialLot $lot): MaterialLot
    {
        return $lot->load(['item', 'bags', 'grn', 'goodsReceiptLine']);
    }

    /**
     * Read-only lot+bag window for Production's traceability report
     * (cross-module read via service, per the module pattern): lots
     * received in the date range, bags eager-loaded, oldest first. The
     * caller maps bags onto day-bin feeds with its own ledger — bag/lot
     * shape stays this module's concern.
     *
     * @return Collection<int, MaterialLot>
     */
    public function lotsForReport(?int $lotId, ?int $itemId, string $dateFrom, string $dateTo): Collection
    {
        return MaterialLot::query()
            ->with(['item', 'bags'])
            ->when($lotId, fn ($query) => $query->whereKey($lotId))
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->whereDate('received_date', '>=', $dateFrom)
            ->whereDate('received_date', '<=', $dateTo)
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create a lot and fan out its bags: n rows, barcode = the supplier's
     * when provided (scannable bags, Vincent Q1) else app-generated
     * LOT{lot}-B{seq} (printable). Per-bag weight = bag_weights[i] when
     * weighed individually, else the nominal bag_weight_kg, else
     * total/count. Duplicate barcodes are impossible by column constraint —
     * request validation gives the friendly 422, the constraint is the
     * hard guard underneath.
     *
     * @param  array{
     *     grn_id?: ?int, goods_receipt_note_line_id?: ?int, item_id: int,
     *     supplier_lot_no?: ?string,
     *     received_date: string, bag_count: int,
     *     bag_weight_kg?: string|float|null, total_received_kg: string|float,
     *     warehouse_id?: ?int, notes?: ?string,
     *     barcodes?: array<int, string>, bag_weights?: array<int, string|float>,
     * }  $data
     */
    public function createLot(array $data, ?int $createdBy): MaterialLot
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $lot = MaterialLot::create([
                'grn_id' => $data['grn_id'] ?? null,
                'goods_receipt_note_line_id' => $data['goods_receipt_note_line_id'] ?? null,
                'item_id' => $data['item_id'],
                'supplier_lot_no' => $data['supplier_lot_no'] ?? null,
                'received_date' => $data['received_date'],
                'bag_count' => $data['bag_count'],
                'bag_weight_kg' => $data['bag_weight_kg'] ?? null,
                'total_received_kg' => $data['total_received_kg'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            $count = (int) $data['bag_count'];
            $barcodes = array_values($data['barcodes'] ?? []);
            $weights = array_values($data['bag_weights'] ?? []);
            $nominal = isset($data['bag_weight_kg'])
                ? bcadd((string) $data['bag_weight_kg'], '0', 4)
                : bcdiv((string) $data['total_received_kg'], (string) $count, 4);

            for ($seq = 1; $seq <= $count; $seq++) {
                $kg = isset($weights[$seq - 1]) ? bcadd((string) $weights[$seq - 1], '0', 4) : $nominal;

                $lot->bags()->create([
                    'barcode' => $barcodes[$seq - 1] ?? sprintf('LOT%d-B%d', $lot->id, $seq),
                    'original_kg' => $kg,
                    'remaining_kg' => $kg,
                    'status' => MaterialBagStatus::InStore,
                    'current_warehouse_id' => $data['warehouse_id'] ?? null,
                ]);
            }

            return $lot->load(['item', 'bags']);
        });
    }

    public function paginateBags(?int $itemId = null, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        return MaterialBag::query()
            ->with('lot.item')
            ->when($itemId, fn ($query) => $query->whereHas('lot', fn ($lot) => $lot->where('item_id', $itemId)))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * FIFO pick list: open in-store bags for the item, oldest
     * received_date first, then lot, then bag sequence — the suggestion
     * the supervisor's scan is checked against.
     *
     * @return Collection<int, MaterialBag>
     */
    public function pickList(int $itemId, int $limit = 50): Collection
    {
        return MaterialBag::query()
            ->select('material_bags.*')
            ->join('material_lots', 'material_lots.id', '=', 'material_bags.material_lot_id')
            ->where('material_lots.item_id', $itemId)
            ->where('material_bags.status', MaterialBagStatus::InStore->value)
            ->where('material_bags.remaining_kg', '>', 0)
            ->orderBy('material_lots.received_date')
            ->orderBy('material_lots.id')
            ->orderBy('material_bags.id')
            ->limit($limit)
            ->with('lot.item')
            ->get();
    }

    /**
     * Scan a bag into a machine's day bin. Full-bag scan (no quantity) =
     * the bag's whole remaining_kg and the bag moves to the bin; partial
     * load (weighed, Vincent Q6) decrements the bag, which stays in the
     * store. Guards: load ≤ remaining_kg (BagOverloadException) and the
     * FIFO policy (FifoPolicyException unless overridden with the
     * production.override-fifo permission — recorded_by says who).
     *
     * @param  array{
     *     material_bag_id?: ?int, barcode?: ?string, work_center_id: int,
     *     shift_production_entry_id?: ?int, quantity_kg?: string|float|null,
     *     override_fifo?: bool,
     * }  $data
     */
    public function loadBagToDayBin(array $data, ?int $userId): DayBinMovement
    {
        return DB::transaction(function () use ($data, $userId) {
            if (isset($data['shift_production_entry_id'])) {
                $segment = ShiftProductionEntry::query()->find($data['shift_production_entry_id']);
                if ($segment === null || $segment->work_center_id !== (int) $data['work_center_id']) {
                    throw ValidationException::withMessages([
                        'shift_production_entry_id' => 'The selected production segment belongs to a different machine.',
                    ]);
                }
            }

            $bag = MaterialBag::query()
                ->when(
                    isset($data['material_bag_id']),
                    fn ($query) => $query->whereKey($data['material_bag_id']),
                    fn ($query) => $query->where('barcode', $data['barcode']),
                )
                ->lockForUpdate()
                ->firstOrFail();
            $lot = $bag->lot()->first();

            $remaining = bcadd((string) $bag->remaining_kg, '0', 4);
            $quantity = isset($data['quantity_kg'])
                ? bcadd((string) $data['quantity_kg'], '0', 4)
                : $remaining;

            if (bccomp($quantity, '0', 4) !== 1 || bccomp($quantity, $remaining, 4) === 1) {
                throw BagOverloadException::make($bag->barcode, $quantity, $remaining);
            }

            $this->enforceFifo($bag, $lot->item_id, (bool) ($data['override_fifo'] ?? false), $userId);

            $fullLoad = bccomp($quantity, $remaining, 4) === 0;
            $bag->remaining_kg = bcsub($remaining, $quantity, 4);
            if ($fullLoad) {
                // The whole bag physically goes to the machine; a partial
                // load pours off the weighed quantity and the bag stays in
                // the store. A load that drives remaining_kg to 0 leaves
                // the bag Consumed (it holds nothing any more) — a later
                // return of kg into it flips it back to InStore.
                $bag->status = MaterialBagStatus::Consumed;
                $bag->day_bin_work_center_id = $data['work_center_id'];
            }
            $bag->save();

            return $this->dayBin->record([
                'work_center_id' => $data['work_center_id'],
                'item_id' => $lot->item_id,
                'shift_production_entry_id' => $data['shift_production_entry_id'] ?? null,
                'type' => DayBinMovementType::Load->value,
                'material_bag_id' => $bag->id,
                'quantity_kg' => $quantity,
                'recorded_by' => $userId,
            ]);
        });
    }

    /**
     * Material back out of the day bin. Named bag: the kg flows back into
     * it and it returns to the store. No bag: ledger row only — where the
     * material physically lands (regrind/return item) is Vincent Q4 and
     * stays undecided here. Guard (in the ledger): never below zero.
     *
     * @param  array{
     *     work_center_id: int, item_id: int, quantity_kg: string|float,
     *     material_bag_id?: ?int, shift_production_entry_id?: ?int,
     * }  $data
     */
    public function returnFromDayBin(array $data, ?int $userId): DayBinMovement
    {
        return DB::transaction(function () use ($data, $userId) {
            $quantity = bcadd((string) $data['quantity_kg'], '0', 4);

            $bag = null;
            if (isset($data['material_bag_id'])) {
                $bag = MaterialBag::query()->whereKey($data['material_bag_id'])->lockForUpdate()->firstOrFail();

                // Cap: a bag can never hold more than it originally did —
                // returning past original_kg would mint material.
                $resulting = bcadd((string) $bag->remaining_kg, $quantity, 4);
                if (bccomp($resulting, (string) $bag->original_kg, 4) === 1) {
                    throw BagOverloadException::forReturn(
                        $bag->barcode,
                        $quantity,
                        (string) $bag->remaining_kg,
                        (string) $bag->original_kg,
                    );
                }
            }

            $movement = $this->dayBin->record([
                'work_center_id' => $data['work_center_id'],
                'item_id' => $data['item_id'],
                'shift_production_entry_id' => $data['shift_production_entry_id'] ?? null,
                'type' => DayBinMovementType::Return->value,
                'material_bag_id' => $data['material_bag_id'] ?? null,
                'quantity_kg' => $data['quantity_kg'],
                'recorded_by' => $userId,
            ]);

            if ($bag !== null) {
                // Material back in the bag, bag back in the store — a
                // Consumed (or legacy InDayBin) bag that holds kg again is
                // simply InStore.
                $bag->remaining_kg = bcadd((string) $bag->remaining_kg, $quantity, 4);
                $bag->status = MaterialBagStatus::InStore;
                $bag->day_bin_work_center_id = null;
                $bag->save();
            }

            return $movement;
        });
    }

    /**
     * A weighed/estimated observation of the bin (Vincent Q2 — method is
     * the floor's, the app just records the figure). With a segment id it
     * is that segment's closing; guards live in the ledger service.
     *
     * @param  array{
     *     work_center_id: int, item_id: int, quantity_kg: string|float,
     *     shift_production_entry_id?: ?int,
     * }  $data
     */
    public function recordCount(array $data, ?int $userId): DayBinMovement
    {
        return $this->dayBin->record([
            'work_center_id' => $data['work_center_id'],
            'item_id' => $data['item_id'],
            'shift_production_entry_id' => $data['shift_production_entry_id'] ?? null,
            'type' => DayBinMovementType::Count->value,
            'quantity_kg' => $data['quantity_kg'],
            'recorded_by' => $userId,
        ]);
    }

    /**
     * FIFO guard: the scanned bag must be the pick-list head (oldest open
     * in-store bag). Anything else needs the explicit override flag plus
     * the production.override-fifo permission — and stays attributed via
     * the movement's recorded_by. Toggled off entirely by config
     * (Vincent Q3: mandatory vs preference).
     */
    private function enforceFifo(MaterialBag $bag, int $itemId, bool $override, ?int $userId): void
    {
        if (! config('production.traceability.fifo_enforced', true)) {
            return;
        }

        $head = $this->pickList($itemId, 1)->first();
        if ($head === null || $head->id === $bag->id) {
            return;
        }

        $user = $userId !== null ? User::query()->find($userId) : null;
        if ($override && $user !== null && $user->can('production.override-fifo')) {
            return;
        }

        throw FifoPolicyException::make($bag->barcode, $head->barcode);
    }

    /**
     * The live day-bin state of one machine: every material with any
     * ledger movement on it (balance from the Production-side ledger) plus
     * the bags physically sitting at the machine right now. The one
     * aggregate the Shift Floor drawer and the handover screen consume.
     *
     * @return array{
     *     materials: array<int, array{
     *         item: array{id: int, name: string, sku: ?string},
     *         balance_kg: string,
     *         loaded_bags: array<int, array{
     *             id: int, barcode: string, remaining_kg: string,
     *             lot: ?array{id: int, supplier_lot_no: ?string},
     *         }>,
     *     }>,
     * }
     */
    public function dayBinStateFor(int $workCenterId): array
    {
        $itemIds = $this->dayBin->itemIdsWithMovements($workCenterId);

        // Every bag that has FED this machine, taken from the ledger rather
        // than from MaterialBag.day_bin_work_center_id.
        //
        // That column is only set on a FULL load — a partial load pours off
        // the weighed kg and leaves the bag in the store — so keying off it
        // made a partially-loaded bag invisible in the machine's Materials
        // view even though its material is in the bin. The load movements
        // name their bag, so they are the truthful source of "what fed this
        // machine".
        $bagIds = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('type', DayBinMovementType::Load->value)
            ->whereNotNull('material_bag_id')
            ->distinct()
            ->pluck('material_bag_id')
            ->all();

        $bagsByItem = MaterialBag::query()
            ->whereIn('id', $bagIds)
            ->with('lot')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (MaterialBag $bag) => $bag->lot?->item_id);

        $items = Item::query()->withTrashed()->findMany($itemIds)->keyBy('id');

        $materials = [];
        foreach ($itemIds as $itemId) {
            $item = $items->get($itemId);
            if ($item === null) {
                continue;
            }

            $materials[] = [
                'item' => ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku],
                'balance_kg' => $this->dayBin->balanceFor($workCenterId, $itemId),
                'loaded_bags' => $bagsByItem->get($itemId, collect())->map(fn (MaterialBag $bag) => [
                    'id' => $bag->id,
                    'barcode' => $bag->barcode,
                    'original_kg' => (string) $bag->original_kg,
                    'remaining_kg' => (string) $bag->remaining_kg,
                    // How much of this bag went into THIS machine's bin —
                    // the figure the supervisor is looking for when they
                    // ask "what did I load off that bag?".
                    'loaded_kg' => (string) DayBinMovement::query()
                        ->where('work_center_id', $workCenterId)
                        ->where('material_bag_id', $bag->id)
                        ->where('type', DayBinMovementType::Load->value)
                        ->sum('quantity_kg'),
                    'lot' => $bag->lot !== null
                        ? ['id' => $bag->lot->id, 'supplier_lot_no' => $bag->lot->supplier_lot_no]
                        : null,
                ])->values()->all(),
            ];
        }

        return ['materials' => $materials];
    }

    /**
     * The segment consumption figure (design formula) — delegated to the
     * Production-side ledger where the movements live; surfaced here so
     * store-facing callers have one traceability entry point.
     *
     * @return array{
     *     opening_kg: string, loaded_kg: string, returned_kg: string,
     *     closing_kg: ?string, consumed_kg: ?string,
     * }
     */
    public function consumptionFor(int $shiftProductionEntryId, int $itemId): array
    {
        return $this->dayBin->consumptionForEntryId($shiftProductionEntryId, $itemId);
    }
}
