<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHAT IS STANDING ON THE PRODUCTION FLOOR RIGHT NOW.
 *
 * Read from **stock balances**, never from request history. That distinction is
 * the whole point of the screen: a request says what was ASKED for and an issue
 * says what was HANDED OVER, but neither says what is still there now. Only the
 * Production/WIP balance does, because consumption and returns move it after
 * the issue is closed. Adding up issues would show a floor that never empties.
 *
 * Production/WIP is a REAL inventory location (DEC-20260817-001:
 * RM Store -> Production/WIP -> FG Store). Material standing here has left the
 * store and has NOT been consumed — the books still hold it as stock. Nothing
 * on this surface is a day bin, and nothing here attributes material to a
 * machine or a batch: a bag belongs to no machine and no batch (FC-01), so the
 * figures are per MATERIAL, never per machine.
 *
 * NO BAG COUNT, deliberately. It was here and it was always null: nothing in
 * the application moves `material_bags.current_warehouse_id` when a bag is
 * issued, so every bag stays flagged in the store for ever and a WIP bag count
 * can only ever be zero. Publishing "—" beside 300 kg of resin would have said
 * "this material is not bag-tracked" on a system where every bag carries a
 * unique barcode. Whether a bag should change location on issue is a custody
 * question for the owner, not something to infer here.
 */
class ProductionFloorStockService
{
    public function __construct(private readonly ProductionWipLocationResolver $wip) {}

    /**
     * Is a Production/WIP location configured at all?
     *
     * The caller needs this to tell two very different empty lists apart:
     * "nothing is standing on the floor" and "nobody has told the ERP where
     * the floor IS". Reporting the second as the first is a false statement
     * about stock.
     */
    public function isConfigured(): bool
    {
        return $this->wip->warehouseId() !== null;
    }

    /**
     * One row per material currently standing in Production/WIP.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function onTheFloor(): Collection
    {
        $wipId = $this->wip->warehouseId();

        if ($wipId === null) {
            // Nothing is configured, so nothing can be truthfully reported.
            // An empty list is honest; a zero would be a claim.
            return collect();
        }

        $balances = StockBalance::query()
            ->with('item:id,sku,name,display_name,uom')
            ->where('warehouse_id', $wipId)
            // NOT `> 0`. A balance of zero is genuinely not on the floor, but a
            // NEGATIVE one is a real, deliberately-unrefused state in this
            // system: a batch may consume more than was ever issued to it
            // (StoreIssueReversalBoundsTest pins WIP at -50 and does not
            // refuse it). Hiding that row would be the worst possible answer —
            // the floor would see nothing for a material it is standing next
            // to, and raise another request, which is the exact failure this
            // panel exists to prevent. The sign is the message.
            ->where('quantity', '!=', 0)
            ->get();

        if ($balances->isEmpty()) {
            return collect();
        }

        $itemIds = $balances->pluck('item_id')->all();

        // BOUNDED. This used to read every non-cancelled issue line ever
        // recorded for these materials and then keep about ten of them in PHP —
        // correct, but growing for ever and re-run on every page load. The
        // newest issued_at per item is computed in the database first, and only
        // those rows are fetched.
        $newestPerItem = DB::table('store_issue_lines as l')
            ->join('store_issues as i', 'i.id', '=', 'l.store_issue_id')
            ->selectRaw('l.item_id as item_id, MAX(i.issued_at) as issued_at')
            ->whereIn('l.item_id', $itemIds)
            ->where('i.status', '!=', 'cancelled')
            ->groupBy('l.item_id');

        $latest = DB::table('store_issue_lines as l')
            ->join('store_issues as i', 'i.id', '=', 'l.store_issue_id')
            ->joinSub($newestPerItem, 'newest', function ($join) {
                $join->on('newest.item_id', '=', 'l.item_id')
                    ->on('newest.issued_at', '=', 'i.issued_at');
            })
            ->leftJoin('users as issuer', 'issuer.id', '=', 'i.issued_by')
            ->leftJoin('users as receiver', 'receiver.id', '=', 'i.received_by')
            ->where('i.status', '!=', 'cancelled')
            ->get(['l.item_id', 'i.issued_at', 'i.issue_number', 'issuer.name as issued_by', 'receiver.name as received_by'])
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->first());

        return $balances
            ->map(function (StockBalance $balance) use ($latest) {
                $last = $latest->get($balance->item_id);

                return [
                    'item_id' => $balance->item_id,
                    'sku' => $balance->item?->sku,
                    // Tally's wire name, and the ERP's own where it is set.
                    'name' => $balance->item?->name,
                    'display_name' => $balance->item?->display_name,
                    'uom' => $balance->item?->uom,
                    'quantity' => (string) $balance->quantity,
                    // ISO8601 WITH ITS OFFSET, exactly as StoreIssueResource
                    // returns it. A raw "2026-08-18 07:06:13" is parsed by the
                    // browser as LOCAL time while the value is UTC, which put
                    // every row 5h30m out and moved a night-shift handover onto
                    // the wrong calendar day.
                    'last_issued_at' => $last?->issued_at === null
                        ? null
                        : CarbonImmutable::parse($last->issued_at)->toIso8601String(),
                    'last_issue_number' => $last->issue_number ?? null,
                    'issued_by' => $last->issued_by ?? null,
                    'received_by' => $last->received_by ?? null,
                ];
            })
            ->sortBy('name')
            ->values();
    }
}
