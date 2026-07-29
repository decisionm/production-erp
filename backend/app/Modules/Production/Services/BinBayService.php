<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\DayBinMovementType;

/**
 * The CENTRAL bin bay: material is loaded into a machine's day bin ONCE, at
 * the bay, by whoever is feeding the machine — not asked for again inside
 * every batch. This service is the read side of that workspace (what is in
 * the bin, which lots it came from, what the run still needs, who loaded
 * what) plus a thin delegation for the write.
 *
 * WHAT A LOAD IS — and is not:
 * loading a bag into a bin bay is an INVENTORY LOCATION MOVEMENT. The
 * material travels from the store to the machine's day bin and nothing
 * else happens: it is NOT consumption, and it NEVER posts a Tally voucher.
 * Consumption is a separate, later figure derived at batch completion
 * (opening + Σ loaded − closing − Σ returned, DayBinLedgerService), and
 * only the approved batch reaches Tally. A bin bay full of resin has
 * consumed nothing.
 *
 * Ownership boundaries this class deliberately respects:
 *  - the actual movement is written by Inventory's
 *    TraceabilityService::loadBagToDayBin (bag remaining_kg, the FIFO
 *    policy and the ledger append in one transaction) — there is exactly
 *    one loader in the system and it is not this one;
 *  - balances come from DayBinLedgerService, never re-derived here;
 *  - the recipe comes from BomService::activeFor — the same path
 *    BatchEstimationService uses for its expected-materials card.
 *
 * Every kg figure leaving this service is a 4dp bcmath STRING, matching the
 * rest of the shift engine (floats would drift against the ledger).
 */
class BinBayService
{
    public function __construct(
        private readonly DayBinLedgerService $ledger,
        private readonly TraceabilityService $traceability,
        private readonly BomService $boms,
    ) {}

    /**
     * What one machine's bin bay holds of one material right now, and which
     * lots it came from.
     *
     * `available_kg` is the ledger balance (counts re-anchor it). The layers
     * are the load movements that fed this bin, OLDEST FIRST — note that is
     * the bin's own FIFO (the order material physically went in), which is
     * not the same ordering as the store pick list's (lot received_date).
     *
     * `in_bin_kg` per layer is DERIVED, not recorded: the current balance
     * allocated across the layers first-in-first-out, so what is left in the
     * bin is attributed to the newest loads. It is an estimate — the ledger
     * never tracks which grain came out of which bag — and it is here so a
     * supervisor can answer "whose lot is in the machine now?". When a count
     * has re-anchored the balance ABOVE everything ever loaded, the excess
     * is reported as `unattributed_kg` rather than being spread over lots
     * that cannot account for it.
     *
     * @return array{
     *     work_center_id: int,
     *     item: ?array{id: int, name: string, sku: ?string, uom: ?string},
     *     available_kg: string, loaded_kg: string, unattributed_kg: string,
     *     layers: list<array{
     *         movement_id: int, material_bag_id: ?int, barcode: ?string,
     *         loaded_kg: string, in_bin_kg: string, recorded_at: ?string,
     *         lot: ?array{id: int, supplier_lot_no: ?string, received_date: ?string},
     *     }>,
     * }
     */
    public function availabilityFor(int $workCenterId, int $itemId): array
    {
        $available = $this->ledger->balanceFor($workCenterId, $itemId);

        $movements = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('type', DayBinMovementType::Load->value)
            ->with(['materialBag.lot'])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $loaded = '0.0000';
        foreach ($movements as $movement) {
            $loaded = bcadd($loaded, (string) $movement->quantity_kg, 4);
        }

        // FIFO: the oldest material left the bin first, so the shortfall
        // between what was loaded and what is left is eaten off the front.
        $allocatable = bccomp($available, $loaded, 4) === 1 ? $loaded : $available;
        $drawnDown = bcsub($loaded, $allocatable, 4);

        $layers = [];
        foreach ($movements as $movement) {
            $layerLoaded = bcadd((string) $movement->quantity_kg, '0', 4);
            $take = bccomp($drawnDown, $layerLoaded, 4) === 1 ? $layerLoaded : $drawnDown;
            $drawnDown = bcsub($drawnDown, $take, 4);

            $bag = $movement->materialBag;
            $lot = $bag?->lot;

            $layers[] = [
                'movement_id' => $movement->id,
                'material_bag_id' => $movement->material_bag_id,
                'barcode' => $bag?->barcode,
                'loaded_kg' => $layerLoaded,
                'in_bin_kg' => bcsub($layerLoaded, $take, 4),
                'recorded_at' => $movement->recorded_at?->toIso8601String(),
                'lot' => $lot !== null ? [
                    'id' => $lot->id,
                    'supplier_lot_no' => $lot->supplier_lot_no,
                    'received_date' => $lot->received_date?->toDateString(),
                ] : null,
            ];
        }

        $item = Item::query()->withTrashed()->find($itemId);

        return [
            'work_center_id' => $workCenterId,
            'item' => $item !== null
                ? ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku, 'uom' => $item->uom]
                : null,
            'available_kg' => $available,
            'loaded_kg' => $loaded,
            'unattributed_kg' => bcsub($available, $allocatable, 4),
            'layers' => $layers,
        ];
    }

    /**
     * "Has this bin bay got enough for the run?" — the product's active
     * recipe priced out against what the machine's day bin actually holds.
     *
     * `$itemId` is the PRODUCT being made; the rows are its recipe
     * components. Expected quantity uses the same recipe path and the same
     * arithmetic as BatchEstimationService's expected-materials card, so the
     * two screens can never quote different numbers.
     *
     * Non-mass components (caps, labels, cartons — anything not in kg) are
     * reported with `is_mass: false` and a NULL shortage: they are not day-bin
     * tracked, so "available 0" would read as a shortage of every consumable
     * on every run, which is noise, not information.
     *
     * `expected_pieces` null (no plan yet) leaves expected and shortage null
     * rather than guessing — a blank is honest, a zero is not.
     *
     * @return array{
     *     product_item_id: int, expected_pieces: ?int, recipe_source: ?string,
     *     components: list<array{
     *         item_id: int, name: string, sku: ?string, uom: ?string,
     *         is_mass: bool, expected_quantity: ?string,
     *         available_quantity: string, shortage_quantity: ?string,
     *     }>,
     * }
     */
    public function expectedVsAvailable(int $itemId, int $workCenterId, ?int $expectedPieces): array
    {
        $bom = $this->boms->activeFor($itemId);

        if ($bom === null || $bom->lines->isEmpty()) {
            return [
                'product_item_id' => $itemId,
                'expected_pieces' => $expectedPieces,
                'recipe_source' => null,
                'components' => [],
            ];
        }

        // activeFor() eager-loads lines only, and a component may since have
        // been soft-deleted — without withTrashed the row silently vanishes
        // from the requirement instead of being flagged.
        $bom->loadMissing(['lines.component' => fn ($query) => $query->withTrashed()]);

        $components = [];
        foreach ($bom->lines as $line) {
            $component = $line->component;
            if ($component === null) {
                continue;
            }

            $isMass = $this->isMassUom($component->uom);
            $expected = $expectedPieces !== null
                ? bcmul((string) $expectedPieces, (string) $line->quantity_per, 4)
                : null;
            $available = $this->ledger->balanceFor($workCenterId, $component->id);

            $shortage = null;
            if ($isMass && $expected !== null) {
                $shortage = bccomp($expected, $available, 4) === 1
                    ? bcsub($expected, $available, 4)
                    : '0.0000';
            }

            $components[] = [
                'item_id' => $component->id,
                'name' => $component->name,
                'sku' => $component->sku,
                'uom' => $component->uom,
                'is_mass' => $isMass,
                'expected_quantity' => $expected,
                'available_quantity' => $available,
                'shortage_quantity' => $shortage,
            ];
        }

        return [
            'product_item_id' => $itemId,
            'expected_pieces' => $expectedPieces,
            'recipe_source' => 'bom',
            'components' => $components,
        ];
    }

    /**
     * Who loaded what into this bin bay, when, off which bag — the audit
     * trail the bay screen shows under the scan box, newest first.
     *
     * @return array{rows: list<array{
     *     id: int, recorded_at: ?string, quantity_kg: string,
     *     shift_production_entry_id: ?int,
     *     item: ?array{id: int, name: string, sku: ?string},
     *     material_bag_id: ?int, barcode: ?string,
     *     lot: ?array{id: int, supplier_lot_no: ?string},
     *     loaded_by: ?array{id: int, name: string},
     * }>}
     */
    public function loadHistoryFor(int $workCenterId, ?int $itemId = null, int $limit = 50): array
    {
        $movements = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('type', DayBinMovementType::Load->value)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->with(['item' => fn ($query) => $query->withTrashed(), 'materialBag.lot', 'recordedBy'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($movements as $movement) {
            $bag = $movement->materialBag;
            $lot = $bag?->lot;
            $item = $movement->item;
            $user = $movement->recordedBy;

            $rows[] = [
                'id' => $movement->id,
                'recorded_at' => $movement->recorded_at?->toIso8601String(),
                'quantity_kg' => bcadd((string) $movement->quantity_kg, '0', 4),
                'shift_production_entry_id' => $movement->shift_production_entry_id,
                'item' => $item !== null
                    ? ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku]
                    : null,
                'material_bag_id' => $movement->material_bag_id,
                'barcode' => $bag?->barcode,
                'lot' => $lot !== null
                    ? ['id' => $lot->id, 'supplier_lot_no' => $lot->supplier_lot_no]
                    : null,
                'loaded_by' => $user !== null ? ['id' => $user->id, 'name' => $user->name] : null,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * The write, delegated verbatim to Inventory's TraceabilityService —
     * bag remaining_kg, the FIFO policy and the ledger row are one
     * transaction there and there is no second loader in this system.
     *
     * `$userId` is the person credited with the load (a users row — the
     * ledger's recorded_by column is a users FK, NOT an employees one).
     *
     * @param  array{
     *     work_center_id: int, barcode?: ?string, material_bag_id?: ?int,
     *     quantity_kg?: string|float|null, shift_production_entry_id?: ?int,
     *     override_fifo?: bool,
     * }  $data
     */
    public function load(array $data, ?int $userId): DayBinMovement
    {
        return $this->traceability->loadBagToDayBin($data, $userId);
    }

    /**
     * Mass UOMs, i.e. the ones the day bin is weighed in. Mirrors
     * BatchEstimationService::isMassUom deliberately: that method is private
     * to another service in this module and sharing it would mean editing a
     * file this workspace does not own — the list is the same three-line
     * normalisation, and the two must be changed together if it ever grows.
     */
    private function isMassUom(?string $uom): bool
    {
        return in_array(rtrim(strtolower(trim((string) $uom)), '.'), ['kg', 'kgs', 'kilogram', 'kilograms'], true);
    }
}
