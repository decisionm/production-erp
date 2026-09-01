<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ProductionAvailabilityService;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Support\Collection;

/**
 * THE CONTROLLED DROPDOWN — what an authorised person may add as consumed
 * when a planned material runs short mid-run.
 *
 * THE OWNER SET THE BREADTH (01-Sep-2026): any ACTIVE stock item, not a
 * pre-declared alternative for the short one. There is deliberately no
 * category rule here — a run that ran out of trays may genuinely have been
 * finished with something the ERP does not think of as a tray, and refusing
 * it would only push the truth off the system, which is how the silent swap
 * happened in the first place.
 *
 * WHAT IS STILL REFUSED, because breadth is not the same as anything goes:
 *
 *  - NOTHING WITH NO USABLE PRODUCTION/WIP STOCK. Material is consumed from
 *    the floor, and the floor is Production/WIP. `usable` is
 *    ProductionAvailabilityService's word, not ours (DEC-20260831-005): a
 *    negative balance is a discrepancy and reads as zero, and a quantity
 *    standing in a unit the item master no longer agrees with is reported but
 *    never counted (FC-03). An item whose only WIP figure is one of those two
 *    is not offered.
 *
 *  - NOTHING THE ENGINE WOULD THEN 422 ON. completeBatch resolves a line's
 *    source warehouse itself when the client sends none, and answers 422 for
 *    an item in no recognised store role. Rather than filter on that guess,
 *    this list HANDS BACK the warehouse each option's stock is actually
 *    standing in and the drawer sends it: a source that was READ is better
 *    than one that was inferred, and the refusal then cannot arise at all.
 *    That warehouse is Production/WIP by construction — it is the location
 *    the usable figure was read from, and consuming from anywhere else would
 *    draw material the floor is not holding.
 *
 * THIS SCREEN IS DELIBERATELY STRICTER THAN THE ENGINE, which is a shape the
 * factory has already chosen once: DEC-20260831-002 has the stock screen
 * under-report 'free to issue' so a storekeeper cannot break a reservation by
 * accident while still being able to do it on purpose. Here the engine will
 * happily issue an item to negative — production.stock.allow_negative_on_
 * completion is true, because material the ledger does not know about is a
 * fact about the shift and not a reason to refuse it — so the dropdown is the
 * only place a bad pick can be caught early. It catches it by not offering
 * it, never by blocking a figure the floor says is true.
 *
 * READ-ONLY. Nothing here writes, moves stock or touches a batch.
 * FC-06: no rate, no cost, no vendor — this is the floor's screen.
 */
class SubstituteMaterialOptionsService
{
    public function __construct(
        private readonly ProductionAvailabilityService $availability,
        private readonly ProductionWipLocationResolver $wip,
    ) {}

    /**
     * @return list<array{item_id: int, name: string, sku: string|null, uom: string|null,
     *     warehouse_id: int, usable_wip_quantity: string, unit_matches: bool}>
     */
    public function options(?string $search = null): array
    {
        $items = Item::query()
            ->where('is_active', true)
            ->when($search !== null && $search !== '', function ($query) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'uom']);

        // No resolvable Production/WIP location means there is no floor to
        // read a figure off. An empty list is the honest answer; guessing a
        // location would offer material against a place it is not standing in.
        $wipId = $this->wip->warehouseId();

        if ($items->isEmpty() || $wipId === null) {
            return [];
        }

        $availability = $this->availability->forItems($items->pluck('id')->all());

        return $items
            ->map(function (Item $item) use ($availability, $wipId) {
                $wip = $availability->get($item->id);

                return [
                    'item_id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'sku' => $item->sku,
                    'uom' => $item->uom,
                    // The location the usable figure was READ from, handed
                    // back so the completion consumes from where the material
                    // actually is rather than from wherever a resolver would
                    // have guessed.
                    'warehouse_id' => $wipId,
                    // The usable figure, by DEC-20260831-005's definition —
                    // never the raw balance.
                    'usable_wip_quantity' => (string) ($wip['usable'] ?? '0'),
                    // Surfaced rather than hidden: an item standing in a unit
                    // the master disagrees with is not offered, and the screen
                    // should be able to say why if it is asked.
                    'unit_matches' => (bool) ($wip['unit_matches'] ?? true),
                ];
            })
            ->filter(fn (array $row) => bccomp($row['usable_wip_quantity'], '0', 4) > 0)
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function collect(?string $search = null): Collection
    {
        return collect($this->options($search));
    }
}
