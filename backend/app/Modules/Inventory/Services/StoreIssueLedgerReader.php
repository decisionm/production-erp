<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * THE READ SIDE of the store-issue ledger, for the readers that used to ask
 * the day bin (Phase 7.5, WS-C).
 *
 * Phase 7.5 retires the Day Bin from the target workflow (DEC-20260817-001:
 * Raw Material Store → Production/WIP → Finished Goods Store, and there is
 * no Day Bin). Material now reaches production as a STORE ISSUE — a signed
 * transfer into Production/WIP — so every reader whose question was "what
 * has gone to production, and which bags were they?" has to ask this ledger
 * instead of, or as well as, `day_bin_movements`.
 *
 * WHY THIS CLASS AND NOT THE MODELS DIRECTLY: Production and Procurement
 * both need these answers, and cross-module reads go through the owning
 * module's service. It is deliberately READ ONLY — nothing here writes, and
 * nothing here decides anything about the issue lifecycle (that is
 * MaterialRequestService and the issue services' job).
 *
 * TWO BOUNDARIES IT KEEPS, both load-bearing:
 *
 *  - **FC-01.** An issue names no batch and, for the common-input resin, no
 *    machine. So nothing here can be asked "which bag did this batch use?"
 *    and nothing here answers it. The furthest a trace goes is the ISSUE:
 *    these bags were issued to production, by whom, when, against which
 *    request. Batch consumption stays calculated.
 *  - **FC-06.** Not one figure below is money and no supplier is named. The
 *    kilograms, the bag and the lot are operational facts every floor login
 *    may see; rates, amounts and vendor identity are Owner/Accounts only and
 *    do not travel through this class at all.
 *
 * A CANCELLED issue is excluded everywhere: its stock was reversed in full,
 * so counting it would claim material stands in production that does not.
 */
class StoreIssueLedgerReader
{
    /**
     * Net kilograms standing with production per item — what the store has
     * issued, less what came back — with the first and last issue timestamps
     * for each.
     *
     * "Net" is per LINE (`quantity_issued − quantity_returned`), not per
     * status: a partially returned issue contributes exactly the part that
     * did not come back. Cancelled issues contribute nothing.
     *
     * Note this is NOT the same question as the Production/WIP stock
     * balance, and must not be confused with it: this is what the STORE
     * handed over, before production burnt any of it. The balance is what is
     * left after consumption. The common-input estimate needs this one,
     * because it subtracts consumption itself.
     *
     * @return array<int, array{kg: string, first_at: CarbonImmutable, last_at: CarbonImmutable}>
     */
    public function netIssuedKgByItem(?array $itemIds = null): array
    {
        $totals = [];

        StoreIssueLine::query()
            ->select(['store_issue_lines.item_id', 'store_issue_lines.quantity_issued', 'store_issue_lines.quantity_returned', 'store_issues.issued_at'])
            ->join('store_issues', 'store_issues.id', '=', 'store_issue_lines.store_issue_id')
            ->where('store_issues.status', '!=', StoreIssueStatus::Cancelled->value)
            ->when($itemIds !== null, fn ($query) => $query->whereIn('store_issue_lines.item_id', $itemIds))
            ->orderBy('store_issue_lines.id')
            ->get()
            ->each(function ($row) use (&$totals) {
                $key = (int) $row->item_id;

                // bcmath in PHP rather than SQL SUM(): SQLite hands a DECIMAL
                // sum back as a float, and a kilogram figure that has been
                // through a float is not one this codebase will print.
                $net = bcsub((string) $row->quantity_issued, (string) $row->quantity_returned, 4);
                $at = CarbonImmutable::parse($row->issued_at);

                if (! isset($totals[$key])) {
                    $totals[$key] = ['kg' => '0.0000', 'first_at' => $at, 'last_at' => $at];
                }

                $totals[$key]['kg'] = bcadd($totals[$key]['kg'], $net, 4);
                if ($at->lessThan($totals[$key]['first_at'])) {
                    $totals[$key]['first_at'] = $at;
                }
                if ($at->greaterThan($totals[$key]['last_at'])) {
                    $totals[$key]['last_at'] = $at;
                }
            });

        return $totals;
    }

    /**
     * Every bag scan naming one of the given bags, oldest first — the
     * store-issue twin of `DayBinLedgerService::loadsForBags`, and the read
     * every trace surface (lot report, purchase-order drawer, carton trace)
     * uses to answer "where did this bag go?" for material issued under the
     * new flow.
     *
     * The answer stops at the issue, by design: the scan carries the bag,
     * the lot, the kilograms, who issued, who received and when, and the
     * request it was raised against. It carries no machine and no batch, and
     * there is nothing here to join one from (FC-01).
     *
     * @param  list<int>  $bagIds
     * @return Collection<int, StoreIssueBagScan>
     */
    public function scansForBags(array $bagIds): Collection
    {
        $bagIds = array_values(array_unique(array_map('intval', $bagIds)));
        if ($bagIds === []) {
            return new Collection;
        }

        return StoreIssueBagScan::query()
            ->whereIn('material_bag_id', $bagIds)
            ->whereHas('storeIssue', fn ($query) => $query->where('status', '!=', StoreIssueStatus::Cancelled->value))
            ->with(['storeIssue', 'line.item', 'lot', 'bag', 'issuedBy', 'receivedBy'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Scans recorded inside a wall-clock window, oldest first — the shape
     * the carton trace needs, which attributes by shift window rather than
     * by any claimed bag-to-batch link (DEC-20260810-001).
     *
     * HALF-OPEN, `[$from, $until)`, deliberately and to the letter of the
     * day-bin query it sits beside (`>= from` and `< until` in
     * FinishedCartonService). Shift windows abut: a scan at exactly the
     * shift end must belong to ONE shift, and an inclusive upper bound would
     * attribute it to both the shift ending and the shift beginning — on a
     * provenance surface, where a kilogram counted twice is a wrong answer
     * about where material went.
     *
     * The lot's GRN and cost versions are eager-loaded because the carton
     * trace's per-lot line reads both (lotAttribution), exactly as the
     * day-bin branch loads them.
     *
     * @return Collection<int, StoreIssueBagScan>
     */
    public function scansBetween(mixed $from, mixed $until): Collection
    {
        return StoreIssueBagScan::query()
            ->where('scanned_at', '>=', $from)
            ->where('scanned_at', '<', $until)
            ->whereHas('storeIssue', fn ($query) => $query->where('status', '!=', StoreIssueStatus::Cancelled->value))
            ->with(['lot.grn', 'lot.costVersions', 'line.item'])
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();
    }

    /** Has anything ever been issued to production through this ledger? */
    public function hasAnyIssue(): bool
    {
        return StoreIssue::query()->where('status', '!=', StoreIssueStatus::Cancelled->value)->exists();
    }
}
