<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ProductionAvailabilityService;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockStateReader;
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
 *  - NOTHING STANDING IN INCOMING-QC HOLD. DEC-20260825-001: material with
 *    bags in waiting_qc may not leave a store's balance through ANY outflow
 *    door, and a production completion's material consumption is named in
 *    that decision explicitly. StockMovementService already refuses it under
 *    the balance lock, so an item offered here on a held quantity is a pick
 *    that 422s on submit — the broken control this class exists to avoid.
 *    The owner's "any active stock item" set the BREADTH of the dropdown; it
 *    did not repeal a decision about what may move.
 *
 *    StockStateReader is the read, not IncomingQcHold::lockAndSum: this is a
 *    screen and must not take bag locks. It is deliberately the stricter of
 *    the two — a bag with no store recorded counts against every store — so
 *    it can only ever offer LESS than the writer would permit, never more.
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
        private readonly StockStateReader $stockState,
    ) {}

    /**
     * @return list<array{item_id: int, name: string, sku: string|null, uom: string|null,
     *     warehouse_id: int, usable_wip_quantity: string, qa_hold_quantity: string,
     *     unit_matches: bool}>
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

        // The incoming-QC hold standing against each item in Production/WIP.
        // Asked for every candidate in one read (this class never loops a
        // query per row), against the usable figure as the on-hand side so
        // the two subtractions agree about what they are subtracting from.
        $qaHold = $this->stockState->forRows(
            $items->map(fn (Item $item) => [
                'item_id' => (int) $item->id,
                'warehouse_id' => $wipId,
                'quantity' => (string) ($availability->get($item->id)['usable'] ?? '0'),
            ])->all(),
        );

        return $items
            ->map(function (Item $item) use ($availability, $wipId, $qaHold) {
                $wip = $availability->get($item->id);

                $state = $qaHold["{$item->id}|{$wipId}"] ?? null;

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
                    // free_to_issue, not the raw balance: DEC-20260831-005's
                    // usable figure, less what is left
                    // after the incoming-QC hold (and any customer
                    // reservation) is taken off. This is the quantity the
                    // floor may actually consume.
                    'usable_wip_quantity' => (string) ($state['free_to_issue'] ?? ($wip['usable'] ?? '0')),
                    'qa_hold_quantity' => (string) ($state['qa_hold'] ?? '0'),
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
