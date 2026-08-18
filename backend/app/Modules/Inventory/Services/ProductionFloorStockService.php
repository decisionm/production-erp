<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
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
 * The bag count is "how many identifiable bags are standing there", which is
 * only meaningful for materials that arrive in bags; everything else reports
 * null rather than a misleading zero.
 */
class ProductionFloorStockService
{
    public function __construct(private readonly ProductionWipLocationResolver $wip) {}

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
            ->with('item:id,sku,name,uom')
            ->where('warehouse_id', $wipId)
            // A balance that has fallen to zero is not "on the floor".
            ->where('quantity', '>', 0)
            ->get();

        if ($balances->isEmpty()) {
            return collect();
        }

        $itemIds = $balances->pluck('item_id')->all();

        // A bag carries no item of its own — it belongs to a LOT, and the lot
        // names the material. Counting only bags that still hold something:
        // an emptied bag is not stock standing on the floor.
        $bagCounts = DB::table('material_bags as b')
            ->join('material_lots as lot', 'lot.id', '=', 'b.material_lot_id')
            ->selectRaw('lot.item_id as item_id, COUNT(*) as bags')
            ->where('b.current_warehouse_id', $wipId)
            ->where('b.remaining_kg', '>', 0)
            ->whereIn('lot.item_id', $itemIds)
            ->groupBy('lot.item_id')
            ->pluck('bags', 'item_id');

        // The most recent HANDOVER of each material — the date the floor
        // actually received it, with the two names on that handover.
        $latest = DB::table('store_issue_lines as l')
            ->join('store_issues as i', 'i.id', '=', 'l.store_issue_id')
            ->leftJoin('users as issuer', 'issuer.id', '=', 'i.issued_by')
            ->leftJoin('users as receiver', 'receiver.id', '=', 'i.received_by')
            ->whereIn('l.item_id', $itemIds)
            ->where('i.status', '!=', 'cancelled')
            ->orderByDesc('i.issued_at')
            ->get(['l.item_id', 'i.issued_at', 'i.issue_number', 'issuer.name as issued_by', 'receiver.name as received_by'])
            // groupBy keeps the first of each — and the query is ordered
            // newest-first, so the first IS the latest.
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->first());

        return $balances
            ->map(function (StockBalance $balance) use ($bagCounts, $latest) {
                $last = $latest->get($balance->item_id);

                return [
                    'item_id' => $balance->item_id,
                    'sku' => $balance->item?->sku,
                    'name' => $balance->item?->name,
                    'uom' => $balance->item?->uom,
                    'quantity' => (string) $balance->quantity,
                    // null, not 0, where bags are not how this material is held.
                    'bag_count' => $bagCounts->get($balance->item_id),
                    'last_issued_at' => $last->issued_at ?? null,
                    'last_issue_number' => $last->issue_number ?? null,
                    'issued_by' => $last->issued_by ?? null,
                    'received_by' => $last->received_by ?? null,
                ];
            })
            ->sortBy('name')
            ->values();
    }
}
