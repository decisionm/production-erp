<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\DayBinMovementType;

/**
 * The READ side of the machine-scoped day-bin ledger: what the ledger holds
 * of a material on one machine (and which lots fed it), and a run's recipe
 * priced against that balance — the Start Batch dialog's shortage figures.
 *
 * THE LOADING SURFACE IS GONE (DEC-20260807-006): the per-machine Bin Bay
 * page, its bin-bay/load write and its bin-bay/history read were removed —
 * the floor's only load flow is the common resin input's bag scan
 * (FactoryDayBinService::loadBag), which names no machine. The
 * machine-stamped Load rows this class still reads are the audit history of
 * how the factory ran under the previous understanding, plus whatever the
 * remaining day-bin endpoints record; they are read here, never written.
 *
 * Ownership boundaries this class deliberately respects:
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
