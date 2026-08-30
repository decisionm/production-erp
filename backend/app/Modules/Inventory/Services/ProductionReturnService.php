<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE WAY HOME FROM THE PRODUCTION AREA — including for material no store
 * issue ever put there.
 *
 * WHY THIS EXISTS. The factory's rule, stated 30-Aug-2026: the store issues
 * material to the production area, production makes finished goods from it
 * during the day, and THE BALANCE IS RETURNED TO THE STORE DAILY. Until
 * this service the only return in the system was
 * StoreIssueService::returnUnused(), which is bounded by — and can only be
 * addressed to — a store issue LINE. That is the right door for material
 * that came through a handover, and it is the only door there was.
 *
 * It is not enough for the rule. On the live instance seven of the nine
 * materials standing in Production/WIP have NO store issue behind them at
 * all (`issued = 0`, a positive WIP quantity, still labelled
 * issued_to_production). StoreIssueService's own docblock already names
 * them: "the WIP row predates this phase and carries rehearsal receipts".
 * Whatever their provenance, they are stock the books hold in production,
 * and there was no way — none — to record them going home. `returned = 0`
 * on every row of a ledger running 2026-07-20 → 2026-08-28 is what that
 * looks like from the outside: not a floor that never returns anything, a
 * system that cannot write it down.
 *
 * ONE DOOR, NOT TWO. A line carries an OPTIONAL store_issue_line_id.
 * Present, the return is attributed and StoreIssueService::returnUnused
 * bounds it and updates the line's arithmetic, exactly as before. Absent,
 * it is unattributed and bounded by the residue rule below. The storekeeper
 * with 20 kg of resin in their hands makes ONE call either way; what they
 * must decide is whether they know which handover it came from, which is a
 * question about the factory, not about the API.
 *
 * IT NEVER ATTRIBUTES FOR THE STOREKEEPER. Nothing here spreads an
 * unattributed return across open issues, FIFO or otherwise. That is the
 * same invention unconsumedBudgets() refuses in the other direction:
 * nothing in this factory can say which handover a given kilogram came out
 * of (FC-01, DEC-20260807-007). The read side (returnable()) shows the
 * split — on the floor, standing against open issues, unattributed — and a
 * person chooses. Whether an end-of-day return MUST be attributed where an
 * issue exists is a factory-flow question for the owner, not a default to
 * be picked here.
 *
 * THE RESIDUE BOUND, and why it is the conservative one:
 *
 *     unattributed(item) = max(0, wip_balance − Σ outstanding on every
 *                               non-cancelled store issue line for that
 *                               item in that location)
 *
 * An unattributed return may only ever draw on the part of the WIP balance
 * that no open handover is standing against. Without that bound, returning
 * "residue" would quietly take the kilograms of a store issue that is still
 * open — the transfer would succeed, because the POOLED balance covers it —
 * and that issue's line would go on claiming material that is no longer
 * there. The same hazard returnUnused() guards with unconsumedBudgets(),
 * seen from the other side.
 *
 * It is deliberately conservative in one direction: consumption is NOT
 * subtracted from the attributed side, so once production has burnt part of
 * an open issue the residue this service will release is smaller than the
 * true residue by that amount. The result is a refusal that names real
 * figures, which a person can answer — where the generous version would
 * move material that belonged to someone else's document, which nobody
 * would ever see. Refuse, never cap: the storekeeper typed a figure, and
 * writing a smaller one under it records a return that did not happen.
 *
 * NO MONEY, NO SUPPLIER (FC-06). Kilograms, counts, people and times only.
 *
 * NO NEW DOCUMENT TABLE, deliberately. Nothing hangs off a return that the
 * movement does not already carry: `created_by`, the date, the quantity,
 * the notes and a reference are all on the stock movement, the pair is
 * signed by TYPE so `inventory:check-ledger` stays green with no change,
 * and Stock Movements already lists it. A ProductionReturn model would add
 * a document number nobody quotes and, without source_type/source_id on the
 * movement, would deepen the attribution debt this module already has.
 * Adding the document later is easy; removing one is not.
 */
class ProductionReturnService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly ProductionWipLocationResolver $wip,
        private readonly StoreIssueService $issues,
        // DELIBERATELY the Sales grammar, not a second one, exactly as
        // ProcurementDocumentQuery took it: how a typed term matches a
        // column is a decision this repo makes ONCE.
        private readonly SalesDocumentQuery $search,
    ) {}

    /**
     * WHAT IS STANDING IN PRODUCTION AND HOW MUCH OF IT MAY COME BACK WHICH WAY.
     *
     * The daily view the return rule needs: one row per material, split into
     * the part that answers an open store issue (with the issue numbers, so
     * the storekeeper can attribute it) and the part that answers nothing.
     *
     * Read from the STOCK BALANCE, like ProductionFloorStockService — the
     * issue ledger says what was handed over, which is a different question
     * and never empties.
     *
     * A NEGATIVE balance is reported, never hidden and never returnable. It
     * is a real state here (a batch may consume more than was issued to it),
     * and a material the floor is standing next to must not vanish from the
     * one screen that lists the floor. Nothing can be returned from it: you
     * cannot send back what the books say is already less than nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function returnable(?string $term = null): Collection
    {
        $wipId = $this->wip->warehouseId();

        if ($wipId === null) {
            // Nowhere is configured as production, so nothing can be
            // truthfully reported standing there. An empty list is honest.
            return collect();
        }

        $balances = StockBalance::query()
            ->with('item:id,sku,name,display_name,uom,is_active')
            ->where('warehouse_id', $wipId)
            ->where('quantity', '!=', 0)
            // SERVER-SIDE, like every other list on this API: a filter applied
            // in the browser is a filter that lies the moment the list is
            // longer than the page. There is deliberately NO pager — this is
            // one row per material PHYSICALLY standing in production, and the
            // whole point of the screen is to send all of it home in one
            // action, which a paged selection cannot do.
            ->when(
                $term !== null && trim($term) !== '',
                fn ($query) => $query->whereHas('item', function ($item) use ($term) {
                    $item->where(function ($either) use ($term) {
                        $this->search->whereLike($either, 'sku', $term);
                        $either->orWhere(fn ($name) => $this->search->whereLike($name, 'name', $term));
                        $either->orWhere(fn ($shown) => $this->search->whereLike($shown, 'display_name', $term));
                    });
                }),
            )
            ->get();

        if ($balances->isEmpty()) {
            return collect();
        }

        $standing = $this->standingByItem($balances->pluck('item_id')->all(), $wipId);

        return $balances
            ->map(function (StockBalance $balance) use ($standing, $wipId) {
                $itemId = (int) $balance->item_id;
                $onFloor = (string) $balance->quantity;
                $lines = $standing->get($itemId, collect());
                $attributed = $lines->reduce(
                    fn (string $carry, array $line) => bcadd($carry, $line['outstanding'], 4),
                    '0.0000',
                );

                return [
                    'item_id' => $itemId,
                    'sku' => $balance->item?->sku,
                    'name' => $balance->item?->name,
                    'display_name' => $balance->item?->display_name,
                    'uom' => $balance->item?->uom,
                    'item_is_active' => (bool) ($balance->item?->is_active ?? false),
                    'warehouse_id' => $wipId,
                    'on_floor' => $onFloor,
                    'attributed' => $attributed,
                    'unattributed' => $this->residue($onFloor, $attributed),
                    'store_issue_lines' => $lines->values()->all(),
                ];
            })
            ->sortBy(fn (array $row) => (string) ($row['display_name'] ?? $row['name'] ?? ''))
            ->values();
    }

    /**
     * RECORD THE RETURN. One call, mixed lines, one transaction.
     *
     * Attributed lines are handed to StoreIssueService::returnUnused, whole
     * and per issue — its bounds, its arithmetic, its refusals, unchanged.
     * They go back to the store the line CAME OUT OF (`from_warehouse_id`),
     * which is a fact about the original handover and is not this caller's
     * to redirect; `toWarehouseId` addresses the unattributed lines only.
     *
     * Everything shares ONE transaction, so a refusal on the last line
     * rolls back the first: a storekeeper who typed one wrong figure has
     * recorded nothing, rather than half a return they now have to reason
     * about.
     *
     * @param  array<int, array{item_id?: int, quantity: string, store_issue_line_id?: int|null}>  $lines
     */
    public function record(array $lines, int $toWarehouseId, int $recordedBy, ?string $notes = null): Collection
    {
        $wipId = $this->wip->warehouseId();

        if ($wipId === null) {
            throw ValidationException::withMessages([
                'lines' => 'No production location is configured, so nothing can be returned from one. '
                    .'Set the Production/WIP warehouse before recording returns.',
            ]);
        }

        $this->assertDestination($toWarehouseId, $wipId);

        return DB::transaction(function () use ($lines, $toWarehouseId, $wipId, $recordedBy, $notes) {
            // Read ONCE, before the first line moves, and spent down as the
            // lines are honoured: two unattributed lines of one material
            // must not each be told the whole residue is theirs.
            $residues = [];
            $moved = collect();

            $attributed = [];

            foreach ($lines as $index => $line) {
                $lineId = isset($line['store_issue_line_id']) ? (int) $line['store_issue_line_id'] : 0;

                if ($lineId > 0) {
                    $attributed[$lineId] = ['index' => $index, 'quantity' => (string) $line['quantity']];

                    continue;
                }

                $itemId = (int) ($line['item_id'] ?? 0);

                if ($itemId <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.item_id" => 'A return that names no store issue line has to name a material.',
                    ]);
                }

                $quantity = bcadd((string) $line['quantity'], '0', 4);

                if (bccomp($quantity, '0', 4) !== 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => 'A return has to be more than zero.',
                    ]);
                }

                $residues[$itemId] ??= $this->unattributedBudget($itemId, $wipId);

                if (bccomp($quantity, $residues[$itemId], 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => $this->residueRefusal($itemId, $wipId, $residues[$itemId], $quantity),
                    ]);
                }

                $residues[$itemId] = bcsub($residues[$itemId], $quantity, 4);

                $this->stock->recordTransfer(
                    itemId: $itemId,
                    fromWarehouseId: $wipId,
                    toWarehouseId: $toWarehouseId,
                    quantity: $quantity,
                    reference: 'Production return — not against a store issue',
                    notes: $notes,
                    createdBy: $recordedBy,
                    purpose: StockMovementPurpose::ReturnFromProduction,
                );

                $moved->push([
                    'item_id' => $itemId,
                    'quantity' => $quantity,
                    'store_issue_line_id' => null,
                    'to_warehouse_id' => $toWarehouseId,
                ]);
            }

            foreach ($this->groupByIssue($attributed) as $issueId => $issueLines) {
                $issue = StoreIssue::query()->whereKey($issueId)->firstOrFail();

                $this->issues->returnUnused(
                    issue: $issue,
                    lines: array_map(
                        fn (array $line) => [
                            'store_issue_line_id' => $line['store_issue_line_id'],
                            'quantity' => $line['quantity'],
                        ],
                        $issueLines,
                    ),
                    recordedBy: $recordedBy,
                    notes: $notes,
                );

                foreach ($issueLines as $line) {
                    $moved->push([
                        'item_id' => $line['item_id'],
                        'quantity' => $line['quantity'],
                        'store_issue_line_id' => $line['store_issue_line_id'],
                        'to_warehouse_id' => $line['to_warehouse_id'],
                    ]);
                }
            }

            return $moved;
        });
    }

    /**
     * The part of a material's production balance that no open handover is
     * standing against — see the class docblock for why it is bounded this
     * way and floored at zero.
     */
    private function unattributedBudget(int $itemId, int $wipId): string
    {
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $wipId)
            ->lockForUpdate()
            ->value('quantity');

        $attributed = $this->standingByItem([$itemId], $wipId)
            ->get($itemId, collect())
            ->reduce(fn (string $carry, array $line) => bcadd($carry, $line['outstanding'], 4), '0.0000');

        return $this->residue((string) ($balance ?? '0'), $attributed);
    }

    /** max(0, on floor − standing against open issues), to 4 places. */
    private function residue(string $onFloor, string $attributed): string
    {
        $left = bcsub($onFloor, $attributed, 4);

        return bccomp($left, '0', 4) === 1 ? $left : '0.0000';
    }

    /**
     * Open store-issue lines per item for one production location, with what
     * each is still standing.
     *
     * Cancelled issues are excluded: their stock was reversed in full, so
     * counting them would protect material that is not there. COMPLETED
     * issues are NOT excluded, and that is deliberate — completing moves no
     * stock, so a completed issue's outstanding quantity is still physically
     * standing in production and still belongs to that document. Treating it
     * as residue would let an unattributed return take it.
     *
     * @param  array<int, int>  $itemIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function standingByItem(array $itemIds, int $wipId): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return StoreIssueLine::query()
            ->join('store_issues', 'store_issues.id', '=', 'store_issue_lines.store_issue_id')
            ->whereIn('store_issue_lines.item_id', $itemIds)
            ->where('store_issue_lines.to_warehouse_id', $wipId)
            ->where('store_issues.status', '!=', 'cancelled')
            ->orderBy('store_issue_lines.id')
            ->get([
                'store_issue_lines.id',
                'store_issue_lines.item_id',
                'store_issue_lines.quantity_issued',
                'store_issue_lines.quantity_returned',
                'store_issue_lines.from_warehouse_id',
                'store_issues.id as issue_id',
                'store_issues.issue_number',
                'store_issues.status',
            ])
            ->map(fn ($line) => [
                'store_issue_line_id' => (int) $line->id,
                'item_id' => (int) $line->item_id,
                'store_issue_id' => (int) $line->issue_id,
                'issue_number' => (string) $line->issue_number,
                'status' => (string) $line->status,
                'outstanding' => bcsub((string) $line->quantity_issued, (string) $line->quantity_returned, 4),
                'to_warehouse_id' => (int) $line->from_warehouse_id,
            ])
            // Nothing standing is nothing to protect and nothing to offer.
            ->filter(fn (array $line) => bccomp($line['outstanding'], '0', 4) === 1)
            ->groupBy('item_id');
    }

    /**
     * Attributed lines, resolved to their issues and checked to belong to a
     * production location, keyed by issue.
     *
     * @param  array<int, array{index: int|string, quantity: string}>  $attributed
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupByIssue(array $attributed): array
    {
        if ($attributed === []) {
            return [];
        }

        $lines = StoreIssueLine::query()
            ->whereIn('id', array_keys($attributed))
            ->get(['id', 'store_issue_id', 'item_id', 'from_warehouse_id'])
            ->keyBy(fn ($line) => (int) $line->id);

        // REFUSE, NEVER DROP. A line id that resolves to nothing would
        // otherwise fall out of the grouping and the caller would be told the
        // return succeeded while that quantity never moved. The FormRequest
        // already refuses it; this is the half that does not depend on which
        // door the call came through.
        foreach ($attributed as $lineId => $ask) {
            if (! $lines->has($lineId)) {
                throw ValidationException::withMessages([
                    "lines.{$ask['index']}.store_issue_line_id" => 'That store issue line does not exist.',
                ]);
            }
        }

        $grouped = [];

        foreach ($lines as $line) {
            $ask = $attributed[(int) $line->id];

            $grouped[(int) $line->store_issue_id][] = [
                'store_issue_line_id' => (int) $line->id,
                'item_id' => (int) $line->item_id,
                'quantity' => $ask['quantity'],
                'to_warehouse_id' => (int) $line->from_warehouse_id,
            ];
        }

        return $grouped;
    }

    /**
     * The destination has to be somewhere a storekeeper can actually put
     * material, and it may not be production itself.
     *
     * Both refusals are made HERE rather than left to recordTransfer: its
     * same-warehouse message ("A transfer must move stock between two
     * different warehouses") is written for a caller with a bug, and it is
     * not what a storekeeper who picked the wrong row from a dropdown needs
     * to read.
     */
    private function assertDestination(int $toWarehouseId, int $wipId): void
    {
        if ($toWarehouseId === $wipId) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'That is the production location the material is coming FROM. '
                    .'Choose the store it is going back to.',
            ]);
        }

        $warehouse = Warehouse::query()->whereKey($toWarehouseId)->first(['id', 'is_active', 'name']);

        if ($warehouse === null || ! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'Material can only be returned to a store that is still in use.',
            ]);
        }
    }

    /** The refusal, in figures a person can check — never a guess, never a cap. */
    private function residueRefusal(int $itemId, int $wipId, string $available, string $asked): string
    {
        $balance = (string) (StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $wipId)
            ->value('quantity') ?? '0');

        $item = Item::query()->whereKey($itemId)->first(['display_name', 'name']);
        $name = $item?->display_name ?: ($item?->name ?: 'This material');

        if (bccomp($balance, '0', 4) !== 1) {
            return sprintf(
                '%s shows %s in production, so nothing can be returned from it. A negative or empty balance is a '
                .'discrepancy to investigate, not stock to send back.',
                $name,
                $balance,
            );
        }

        return sprintf(
            '%s has %s standing in production, but only %s of it answers no store issue. A return of %s would take '
            .'material an open store issue is still holding — return that part against its own issue line instead.',
            $name,
            $balance,
            $available,
            $asked,
        );
    }
}
