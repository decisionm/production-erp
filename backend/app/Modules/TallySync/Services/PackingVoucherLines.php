<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\PackingMaterialMappingService;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use App\Modules\Production\Services\ProductionStandardResolver;

/**
 * THE PACKING HALF OF A BATCH VOUCHER — which of a batch's consumption lines
 * are packing materials, which godown they post out of, and which of them the
 * factory has told us NOT to post yet.
 *
 * The owner's ruling (30-Jul), verbatim on both counts:
 *
 *   "outward lines for resin, masterbatch and every packing material actually
 *    used, including cartons, trays, film pouches and tape where applicable.
 *    Raw materials from the agreed RM or machine-WIP location, packing
 *    materials from the Packing Material Store…"
 *
 *   "Calculate tape from the exact metres-per-carton standard. Do not post
 *    tape until its Tally unit is metres or there is an exact approved
 *    conversion."
 *
 * Two questions follow from that, and this class answers exactly those two.
 *
 * ## 1. Is this line a packing material, and where does it post from?
 *
 * A line is packing material when the factory's OWN packing-material master
 * says so — a `packing_material_mappings` row naming that item. Not the item's
 * unit (this factory's masters read "NOS" for film Tally moves in Kgs), not the
 * item name, not a hardcoded list: the one place the factory has already
 * answered "which Tally item is the 170ML carton" is the place that answers
 * "is this a carton". Read through PackingMaterialMappingService, never through
 * the model, because the mappings belong to Production.
 *
 * Those lines post out of the PACKING MATERIAL STORE — a warehouse role on
 * FactoryWarehouseResolver, resolved server-side exactly like finished goods
 * and raw material, so nobody on the floor is ever asked. When the role has no
 * answer the line keeps its own godown and the PREVIEW refuses the voucher
 * (VoucherPreviewService), which is the fail-closed half of the same rule:
 * posting cartons out of the resin store is not better than not posting.
 *
 * ## 2. May the CALCULATED tape post at all?
 *
 * Not yet, and that decision is NOT this class's. PackingMaterialSuggestionService
 * is the one place that decides whether a tape figure may be filed as stock
 * (`submit_as_stock`), because tape is dosed in METRES per box while Tally
 * counts "Packing Tape - Transparent" in Nos, and whether one No is a metre or
 * a whole roll is a question only the factory can settle. This class asks that
 * service, and when the answer is still "open" it CALCULATES the tape anyway —
 * cartons × the exact metres-per-box standard — and carries it on the payload
 * as a WITHHELD line, so the accountant sees the figure and the reason instead
 * of a voucher that silently says nothing about tape.
 *
 * IT NEVER TOUCHES A SUBMITTED LINE. If a supervisor issued three rolls of
 * tape, three rolls is a counted fact and it posts like every other consumption
 * line — the open question is about a CALCULATED length, not about a person's
 * count, and PackingMaterialMappingTest holds the whole system to "the voucher
 * carries the submitted lines, not the suggestions". calculatedTape() therefore
 * stands down entirely the moment the tape is already on the voucher.
 *
 * The moment the factory answers (metres per unit on the mapping, or the tape
 * item's own unit corrected to a length), `submit_as_stock` flips, the
 * completion submits the line like any other, and it posts through the ordinary
 * path above with no deploy and no change here.
 */
class PackingVoucherLines
{
    /** Withheld-line kinds, as they appear on the payload and the preview. */
    public const WITHHELD_TAPE = 'tape';

    public const WITHHELD_SCRAP = 'scrap';

    /**
     * What the accountant is told when the calculated tape is held back and the
     * suggestion carries no sentence of its own to say it with.
     */
    private const TAPE_HELD_BACK = 'Tally counts this tape in Nos, and whether one No is a metre or a whole roll '
        .'is still open with the factory — so these metres are not a Tally quantity and no tape line is posted. '
        .'Set metres per unit on the tape mapping, or correct the item\'s unit to metres, and it will post like '
        .'every other packing line.';

    /** @var array<int, list<string>>|null itemId => the kinds it is mapped as */
    private ?array $kinds = null;

    private bool $storeLookedUp = false;

    private ?string $storeName = null;

    /** @var array<int, array<string, mixed>|null> entry id => its tape suggestion */
    private array $tapeSuggestions = [];

    public function __construct(
        private readonly PackingMaterialMappingService $mappings,
        private readonly PackingMaterialSuggestionService $suggestions,
        private readonly ProductionStandardResolver $standards,
        private readonly FactoryWarehouseResolver $warehouses,
        private readonly TallyGodownResolver $godowns,
    ) {}

    /**
     * The godown name every packing line posts out of, or null when this
     * factory has not named a packing-material store yet.
     *
     * `method_exists` guards the ORDER OF ARRIVAL, not the role's existence:
     * FactoryWarehouseResolver::packingMaterial() belongs to Production and
     * this module has no say in which half ships first. Without it the answer
     * is simply "no store named" — the same answer an unconfigured setting
     * gives, failing closed in the preview — instead of a fatal on the
     * accountant's approval screen.
     */
    public function godownName(): ?string
    {
        if (! $this->storeLookedUp) {
            $this->storeLookedUp = true;

            $warehouse = method_exists($this->warehouses, 'packingMaterial')
                ? $this->warehouses->packingMaterial()
                : null;

            $this->storeName = $warehouse === null ? null : $this->godowns->resolveName($warehouse);
        }

        return $this->storeName;
    }

    /**
     * Which packing material this item IS — 'carton', 'tray', 'pouch_film',
     * 'tape' — or null when the factory's packing master says nothing about it
     * (resin, masterbatch, caps: ordinary raw material).
     *
     * Tape wins when an item is mapped under more than one kind, because tape
     * is the only kind whose postability is in question and reading it as a
     * carton would post the very metres this class exists to hold back. Which
     * of the OTHER kinds wins is arbitrary (the mappings arrive kind-ordered),
     * and that is safe only because every caller today asks "is this packing
     * material at all" — godown routing and the preview's missing-store
     * problem. Anything that branches on the specific kind has to settle the
     * tie properly first.
     */
    public function kindFor(int $itemId): ?string
    {
        if ($this->kinds === null) {
            $this->kinds = [];

            foreach ($this->mappings->all() as $mapping) {
                if ($mapping->item_id === null) {
                    continue;
                }

                $this->kinds[(int) $mapping->item_id][] = (string) $mapping->spec_kind;
            }
        }

        $kinds = $this->kinds[$itemId] ?? null;

        if ($kinds === null) {
            return null;
        }

        return in_array(PackingMaterialMapping::KIND_TAPE, $kinds, true)
            ? PackingMaterialMapping::KIND_TAPE
            : $kinds[0];
    }

    /**
     * The tape this batch used, calculated from the exact metres-per-carton
     * standard and carried as a WITHHELD line — the owner's "calculate tape…
     * do not post tape until…", both halves in one entry.
     *
     * Null — no line at all, rather than a zero — when there is nothing honest
     * to state:
     *  - the run's standard names no tape, or its mapping carries no metres;
     *  - the units already agree, in which case the completion submits the tape
     *    as an ordinary consumption line and synthesising a second one here
     *    would double it;
     *  - the tape is already ON the voucher's consumption lines, same reason;
     *  - no carton was packed, so nothing was sealed. This mirrors the
     *    suggestion service's own rule: a product with no carton has no tape
     *    line, not a blank one.
     *
     * @param  list<int>  $consumedItemIds  items already on the voucher
     * @return array{kind: string, item: ?string, quantity: string, unit: string, reason: string}|null
     */
    public function calculatedTape(ShiftProductionEntry $entry, array $consumedItemIds): ?array
    {
        $suggestion = $this->tapeSuggestion($entry);

        if ($suggestion === null || ($suggestion['submit_as_stock'] ?? false) === true) {
            return null;
        }

        $itemId = $suggestion['item']['id'] ?? null;
        $factor = $suggestion['factor'] ?? null;

        if ($itemId === null || $factor === null || in_array((int) $itemId, $consumedItemIds, true)) {
            return null;
        }

        $cartons = (int) ($entry->no_of_box ?? 0);

        if ($cartons <= 0) {
            return null;
        }

        $metres = bcmul((string) $cartons, (string) $factor, 4);

        return [
            'kind' => self::WITHHELD_TAPE,
            'item' => $suggestion['item']['name'] ?? null,
            'quantity' => $metres,
            'unit' => 'm',
            'reason' => "{$cartons} cartons × {$factor} m = {$metres} m. "
                .(string) ($suggestion['reason'] ?? self::TAPE_HELD_BACK),
        ];
    }

    /**
     * The run's tape suggestion, or null. Memoised per entry: the payload
     * builder asks once per consumption line and once more for the calculated
     * line, and the answer cannot change inside one voucher build.
     *
     * The standard is resolved from the id FROZEN ON THE RUN, not from whatever
     * standard the product carries today — a voucher must describe the batch
     * that ran, and this factory's products have several standard variants.
     *
     * @return array<string, mixed>|null
     */
    private function tapeSuggestion(ShiftProductionEntry $entry): ?array
    {
        $key = (int) $entry->id;

        if (array_key_exists($key, $this->tapeSuggestions)) {
            return $this->tapeSuggestions[$key];
        }

        $standard = $entry->item_id === null
            ? null
            : $this->standards->resolve((int) $entry->item_id, $entry->production_standard_id);

        $tape = null;

        foreach ($this->suggestions->forStandard($standard) as $suggestion) {
            if (($suggestion['kind'] ?? null) === PackingMaterialMapping::KIND_TAPE) {
                $tape = $suggestion;
                break;
            }
        }

        return $this->tapeSuggestions[$key] = $tape;
    }
}
