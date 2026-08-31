<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssueLine;
use Illuminate\Support\Collection;

/**
 * WHAT IS ALREADY ON THE FLOOR, AND MAY THEREFORE BE ASKED FOR LESS OF.
 *
 * DEC-20260831-001: material not returned at the end of production REMAINS
 * AVAILABLE in Production/WIP and is the next day's opening material — it is
 * not consumed, not written off, and not moved by the passing of a day. So
 * when the next request is prepared the ERP must take account of it, and the
 * screen must show three figures: total required, quantity already available
 * in Production/WIP, and the balance to request from the store, which is the
 * first minus the second, floored at zero.
 *
 * THE FLOOR AT ZERO IS THE WHOLE POINT of the third figure. A material the
 * floor already has more of than it needs asks the store for NOTHING, and a
 * negative balance to request is not a number a storekeeper can act on.
 *
 * "USABLE" IS NARROWER THAN "PRESENT", and each exclusion is a refusal to
 * net off something that is not really there:
 *
 *  - A NEGATIVE balance is not stock. It is a discrepancy (a batch may
 *    consume more than was ever issued to it), and netting a negative would
 *    make the floor ask the store for MORE than it needs to cover an error
 *    nobody has looked at. Treated as zero available; the request is unnetted
 *    and the discrepancy stays visible where it already is.
 *
 *  - A UNIT MISMATCH is not netted, and this is FC-03 in the one place the
 *    system has evidence of one. A request line snapshots the ITEM's current
 *    unit; the material standing in production was handed over on store issue
 *    lines that snapshot the unit of the day. `ItemService::upsertFromTally`
 *    overwrites `items.uom` from Tally's BASEUNITS on every masters pull,
 *    unattended, so the two CAN disagree without anybody editing anything.
 *    When they do, the WIP quantity is a number about a different thing —
 *    229 metres of tape filed as 229 Nos reached this factory once — and
 *    subtracting it would under-order real material. The quantity is
 *    reported, flagged, and NOT subtracted.
 *
 * ORPHAN MATERIAL — standing in production with no store issue behind it, as
 * seven of the nine live materials are — has no recorded handover unit to
 * disagree with. It is denominated in the item's own unit by construction
 * (a stock balance carries no unit of its own), so it nets normally. Refusing
 * it would be inventing a mismatch rather than finding one.
 *
 * NOTHING HERE WRITES. It is read by the request screen before a request
 * exists and by MaterialRequestService when one is raised, and the figure it
 * returns is a snapshot: the WIP balance moves every time a batch consumes or
 * a return comes home, which is exactly why the request records what it saw.
 */
class ProductionAvailabilityService
{
    public function __construct(private readonly ProductionWipLocationResolver $wip) {}

    /**
     * What is usably standing in production, per item.
     *
     * @param  array<int, int>  $itemIds
     * @return Collection<int, array{quantity: string, usable: string, uom: string|null, unit_matches: bool, handover_uom: string|null}>
     */
    public function forItems(array $itemIds): Collection
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return collect();
        }

        $wipId = $this->wip->warehouseId();

        if ($wipId === null) {
            // Nowhere is configured as production, so nothing can truthfully
            // be said to be standing there — and netting against a figure
            // nobody configured would silently under-order.
            return collect();
        }

        $units = Item::query()->whereIn('id', $itemIds)->pluck('uom', 'id');
        $handovers = $this->handoverUnits($itemIds, $wipId);

        return StockBalance::query()
            ->whereIn('item_id', $itemIds)
            ->where('warehouse_id', $wipId)
            ->get(['item_id', 'quantity'])
            ->mapWithKeys(function (StockBalance $balance) use ($units, $handovers) {
                $itemId = (int) $balance->item_id;
                $quantity = bcadd((string) $balance->quantity, '0', 4);
                $itemUom = $units[$itemId] ?? null;
                $handoverUom = $handovers[$itemId] ?? null;

                $matches = $handoverUom === null || $this->sameUnit($handoverUom, $itemUom);
                $positive = bccomp($quantity, '0', 4) === 1;

                return [$itemId => [
                    'quantity' => $quantity,
                    'usable' => $matches && $positive ? $quantity : '0.0000',
                    'uom' => $itemUom,
                    'unit_matches' => $matches,
                    'handover_uom' => $handoverUom,
                ]];
            });
    }

    /** The usable figure alone, for one item — "0.0000" when there is none. */
    public function usableFor(int $itemId): string
    {
        return $this->forItems([$itemId])->get($itemId)['usable'] ?? '0.0000';
    }

    /**
     * The balance to request: total required less what is usably standing in
     * production, floored at zero.
     */
    public function balanceToRequest(string $required, string $usable): string
    {
        $balance = bcsub(bcadd($required, '0', 4), bcadd($usable, '0', 4), 4);

        return bccomp($balance, '0', 4) === 1 ? $balance : '0.0000';
    }

    /**
     * The unit each item's standing production material was handed over in,
     * where a handover recorded one.
     *
     * The NEWEST open handover's unit is the one that counts: if the unit was
     * corrected between two issues, the recent one describes what is on the
     * floor now. Cancelled issues are excluded — their stock was reversed, so
     * their unit describes nothing.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, string>
     */
    private function handoverUnits(array $itemIds, int $wipId): array
    {
        return StoreIssueLine::query()
            ->join('store_issues', 'store_issues.id', '=', 'store_issue_lines.store_issue_id')
            ->whereIn('store_issue_lines.item_id', $itemIds)
            ->where('store_issue_lines.to_warehouse_id', $wipId)
            ->where('store_issues.status', '!=', StoreIssueStatus::Cancelled->value)
            ->whereNotNull('store_issue_lines.uom')
            ->orderBy('store_issue_lines.id')
            ->get(['store_issue_lines.item_id', 'store_issue_lines.uom'])
            ->mapWithKeys(fn ($line) => [(int) $line->item_id => (string) $line->uom])
            ->all();
    }

    /**
     * The same unit, spelled either way.
     *
     * Trimmed and case-folded only. NOT normalised through MeasurementType:
     * that answers "may this be fractional", which puts Kgs. and Litres in one
     * class — and netting litres off kilograms is the exact mistake FC-03 is
     * about. Two units are the same here only if they are the same word.
     */
    private function sameUnit(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
