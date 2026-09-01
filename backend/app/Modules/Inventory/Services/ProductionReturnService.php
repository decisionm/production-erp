<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
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
 * of (FC-01, DEC-20260807-007).
 *
 * AND WHERE THE ANSWER IS NOT RECORDED, IT REFUSES RATHER THAN PICKS.
 * Whether an end-of-day return MUST be attributed to an open handover is
 * Q69, open with the owner. An earlier version showed the split and let the
 * storekeeper choose — which sounds like leaving the decision to a person,
 * and is not: shipping the capability IS answering Q69 "storekeeper's
 * choice", and answering it that way writes records that CANNOT BE UNDONE.
 * An unattributed movement can never be re-attributed afterwards, so if the
 * owner answers "must attribute", every one of them has left a handover
 * claiming material that went home weeks ago.
 *
 * So: a material with an OPEN store issue standing on it in production
 * refuses an unattributed return, naming the issue and the other door
 * (AGENTS.md — add the question, stop that part, do not choose for the
 * factory). A material with NO open handover behind it — every one of the
 * seven the live instance could not bring home — is untouched by this,
 * which is the whole case that provoked the build. When the owner answers,
 * relaxing this is a deleted condition.
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
        // WHERE A DAMAGED RETURN GOES INSTEAD OF THE STORE (DEC-20260901-003).
        // Resolved per return rather than held, and it REFUSES rather than
        // falling back — see QualityHoldLocationResolver for why a fallback
        // here would be the one outcome the rule forbids.
        private readonly QualityHoldLocationResolver $qualityHold,
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
            // ATTRIBUTED LINES ARE RESOLVED AND LOCKED FIRST, and the order
            // is the point: StoreIssueService::returnUnused locks the ISSUE
            // LINE and then moves stock. Taking them the other way round would
            // give two concurrent returns of one material opposite orders, and
            // InnoDB resolves that by killing one — at the end of a shift,
            // which is the only time this screen is used.
            //
            // Only store_issue_lines is locked here. NOTHING on this path
            // locks a balance before the transfer does, because the transfer's
            // own order — bags, then balances — is pinned by
            // StockOutflowQcHoldTest and must not be reversed.
            $attributed = $this->lockAttributedLines($this->attributedAsks($lines), $wipId);

            // Read ONCE, before the first line moves, and spent down as the
            // lines are honoured: two unattributed lines of one material
            // must not each be told the whole residue is theirs.
            $residues = $this->budgetsFor($this->unattributedItemIds($lines), $wipId);
            $moved = collect();

            // IN ITEM ORDER, NOT THE ORDER THEY WERE TYPED. Each move locks
            // that material's balances, so two returns sent at the same moment
            // — one listing resin then film, the other film then resin — would
            // each hold their first material and wait for the other's, and
            // InnoDB would kill one. Everything below still reports against
            // the line index the caller sent.
            foreach ($this->inItemOrder($lines) as $index => $line) {
                $lineId = isset($line['store_issue_line_id']) ? (int) $line['store_issue_line_id'] : 0;

                if ($lineId > 0) {
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

                $residues[$itemId] ??= '0.0000';

                if (bccomp($this->standingAgainstIssues($itemId, $wipId), '0', 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => $this->attributionRequiredRefusal($itemId, $wipId),
                    ]);
                }

                if (bccomp($quantity, $residues[$itemId], 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => $this->residueRefusal($itemId, $wipId, $residues[$itemId], $quantity),
                    ]);
                }

                $residues[$itemId] = bcsub($residues[$itemId], $quantity, 4);

                $qualityState = ReturnedQualityState::fromNullable($line['quality_state'] ?? null);

                // THE DESTINATION IS DECIDED BY THE CONDITION, not by the
                // dropdown (DEC-20260901-003). A good line goes to the store
                // the storekeeper picked; a damaged one goes to quality hold
                // and the picked store is not consulted at all — it is the
                // one place a person's choice must not be able to put
                // damaged material back into issuable stock.
                $destination = $this->destinationFor($qualityState, $toWarehouseId, "lines.{$index}");

                $this->stock->recordTransfer(
                    itemId: $itemId,
                    fromWarehouseId: $wipId,
                    toWarehouseId: $destination,
                    quantity: $quantity,
                    reference: $this->referenceFor($qualityState, 'Production return — not against a store issue'),
                    notes: $notes,
                    createdBy: $recordedBy,
                    purpose: StockMovementPurpose::ReturnFromProduction,
                    qualityState: $qualityState,
                );

                $moved->push([
                    'item_id' => $itemId,
                    'quantity' => $quantity,
                    'store_issue_line_id' => null,
                    // WHERE IT ACTUALLY WENT, which for a damaged line is not
                    // the warehouse the caller asked for. The receipt must say
                    // the true destination or the screen reports a move that
                    // did not happen.
                    'to_warehouse_id' => $destination,
                    'quality_state' => $qualityState->value,
                ]);
            }

            // THE GUARANTEE, taken after the unattributed moves and before
            // the attributed ones: whatever went home, what is left standing
            // in production must still cover every open handover.
            $this->assertNothingTakenFromAnOpenIssue($this->unattributedItemIds($lines), $wipId);

            foreach ($attributed as $issueId => $issueLines) {
                $issue = StoreIssue::query()->whereKey($issueId)->firstOrFail();

                $this->issues->returnUnused(
                    issue: $issue,
                    lines: array_map(
                        fn (array $line) => [
                            'store_issue_line_id' => $line['store_issue_line_id'],
                            'quantity' => $line['quantity'],
                            // Carried, not defaulted here: returnUnused reads
                            // a missing key as `good` in one place, so the two
                            // halves of the same return cannot disagree.
                            'quality_state' => $line['quality_state'] ?? null,
                            // AND WHERE IT GOES. returnUnused normally sends
                            // material back to the store it was ISSUED from —
                            // a fact about the original handover — and that is
                            // exactly the destination a DAMAGED line must not
                            // use. Resolved once, in lockAttributedLines, so
                            // both doors refuse identically on an unconfigured
                            // hold and neither re-decides it here.
                            'to_warehouse_id' => $line['to_warehouse_id'],
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
                        // Same reading of a missing key as the unattributed
                        // half above, so the receipt a storekeeper gets back
                        // says the same thing whichever door they used.
                        'quality_state' => ReturnedQualityState::fromNullable($line['quality_state'] ?? null)->value,
                    ]);
                }
            }

            return $moved;
        });
    }

    /**
     * The materials on this return that answer no store issue line.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, int>
     */
    private function unattributedItemIds(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            if (isset($line['store_issue_line_id']) && (int) $line['store_issue_line_id'] > 0) {
                continue;
            }

            $itemId = (int) ($line['item_id'] ?? 0);

            if ($itemId > 0) {
                $ids[$itemId] = $itemId;
            }
        }

        return array_values($ids);
    }

    /**
     * The part of each material's production balance that no open handover is
     * standing against — see the class docblock for why it is bounded this
     * way and floored at zero.
     *
     * READ ONCE, FOR EVERY MATERIAL ON THE RETURN, BEFORE THE FIRST LINE
     * MOVES, and spent down as the lines are honoured: two lines of one
     * material must not each be told the whole residue is theirs.
     *
     * THESE READS TAKE NO LOCK, deliberately, and that is a correction.
     *
     * An earlier version locked the balance here, before the transfer read
     * `material_bags`. `StockOutflowQcHoldTest::test_bags_are_read_before_
     * balances_in_every_decrement` pins the opposite order — BAGS FIRST,
     * BALANCE SECOND, on every outflow door, "so no pair of these can wait on
     * each other" — and reversing it here would have made this door the one
     * pair that can. Trading a theoretical deadlock for a pinned one is not a
     * fix.
     *
     * So this is a PRE-CHECK, not the guarantee: it exists to refuse early
     * with figures a person can read. The guarantee is `assertNothingTakenFrom
     * AnOpenIssue()`, which re-reads the same arithmetic AFTER the moves —
     * under the locks the transfers themselves took, in the contract's order.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, string>
     */
    private function budgetsFor(array $itemIds, int $wipId): array
    {
        if ($itemIds === []) {
            return [];
        }

        sort($itemIds);

        $balances = [];

        foreach ($itemIds as $itemId) {
            $balances[$itemId] = $this->balanceOf($itemId, $wipId);
        }

        // One join for every material on the return, not one per line.
        $standing = $this->standingByItem($itemIds, $wipId);
        $budgets = [];

        foreach ($itemIds as $itemId) {
            $attributed = $standing
                ->get($itemId, collect())
                ->reduce(fn (string $carry, array $line) => bcadd($carry, $line['outstanding'], 4), '0.0000');

            $budgets[$itemId] = $this->residue($balances[$itemId], $attributed);
        }

        return $budgets;
    }

    /**
     * AFTER THE MOVES: is every open handover still covered by what is
     * standing in production?
     *
     * This is the real bound, and the pre-check in budgetsFor() is only its
     * friendlier face. By the time this runs the transfers have taken their
     * own row locks — in the order `StockOutflowQcHoldTest` pins, bags before
     * balances — so the figures it reads are the serialized ones. A return
     * that raced another return of the same material past the pre-check is
     * refused here, and the whole transaction rolls back.
     *
     * @param  array<int, int>  $itemIds
     */
    private function assertNothingTakenFromAnOpenIssue(array $itemIds, int $wipId): void
    {
        if ($itemIds === []) {
            return;
        }

        $standing = $this->standingByItem($itemIds, $wipId);

        foreach ($itemIds as $itemId) {
            $attributed = $standing
                ->get($itemId, collect())
                ->reduce(fn (string $carry, array $line) => bcadd($carry, $line['outstanding'], 4), '0.0000');

            $left = $this->balanceOf($itemId, $wipId);

            if (bccomp($left, $attributed, 4) === -1) {
                throw ValidationException::withMessages([
                    'lines' => $this->residueRefusal(
                        $itemId,
                        $wipId,
                        '0.0000',
                        bcsub($attributed, $left, 4),
                    ),
                ]);
            }
        }
    }

    /**
     * The caller's lines, keyed by their original index, ordered by material.
     *
     * A line with no material sorts first so its refusal is still reached.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function inItemOrder(array $lines): array
    {
        uasort($lines, fn ($a, $b) => ((int) ($a['item_id'] ?? 0)) <=> ((int) ($b['item_id'] ?? 0)));

        return $lines;
    }

    /** What every open handover is standing on, for one material in production. */
    private function standingAgainstIssues(int $itemId, int $wipId): string
    {
        return $this->standingByItem([$itemId], $wipId)
            ->get($itemId, collect())
            ->reduce(fn (string $carry, array $line) => bcadd($carry, $line['outstanding'], 4), '0.0000');
    }

    /**
     * The refusal for material a handover is still standing on. It names the
     * other door, because that door always works.
     *
     * SETTLED 31-Aug-2026 (DEC-20260831-005, refined by
     * DEC-20260831-008), and it used to be an open
     * question. Until then this refusal existed because the owner had not
     * ruled on whether a storekeeper MAY choose to record such a return
     * without naming its handover, and refusing was the conservative
     * direction: an unattributed movement can never be re-attributed
     * afterwards, so guessing wrong was the one mistake that could not be
     * undone. The answer is that material which came through a store issue
     * must return against that exact issue — for open, partially returned
     * AND completed issues, while returnable quantity remains — so the
     * refusal stays and stops apologising for itself.
     *
     * `standingByItem` already carries the completed case (it excludes only
     * CANCELLED issues and keeps any line still outstanding), which is why
     * the rule needed no new condition, only settled words.
     */
    private function attributionRequiredRefusal(int $itemId, int $wipId): string
    {
        $issues = $this->standingByItem([$itemId], $wipId)
            ->get($itemId, collect())
            ->pluck('issue_number')
            ->unique()
            ->implode(', ');

        $item = Item::query()->whereKey($itemId)->first(['display_name', 'name']);
        $name = $item?->display_name ?: ($item?->name ?: 'This material');

        return sprintf(
            '%s came out of the store on store issue %s and has to go back against it, so that handover\'s own '
            .'arithmetic closes. Open it in the Issues tab and return it from there. Material with no store '
            .'issue behind it is the only kind that comes home without naming one.',
            $name,
            $issues,
        );
    }

    /** What the books hold for one material in one location, to 4 places. */
    private function balanceOf(int $itemId, int $warehouseId): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity') ?? '0');
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
     * What each named store issue line is being asked to take back, SUMMED.
     *
     * TWO LINES NAMING ONE HANDOVER LINE ARE ADDED TOGETHER, never replaced.
     * Keying by line id and assigning would keep the LAST quantity and drop
     * the rest — a caller believing it returned 10 + 20 while 20 moved, and a
     * 201 saying it worked. Summing is also what the store-issue return does
     * with duplicate ids, so the two doors agree.
     *
     * The first index is kept so a refusal points at a line the caller sent.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{index: int|string, quantity: string}>
     */
    /**
     * WHERE A RETURNED LINE ACTUALLY LANDS (DEC-20260901-003).
     *
     * `good` goes where the caller said. `damaged` goes to quality hold, and
     * the caller's destination is not consulted — a damaged line may never
     * reach issuable stock, and letting a payload name its own destination
     * for one is the hole this method exists to close.
     *
     * IT REFUSES RATHER THAN FALLING BACK when no quality-hold location is
     * configured. The refusal is attached to the LINE that caused it, so a
     * storekeeper returning six materials is told which one stopped, and it
     * offers the recoverable alternative (record it as good if it is not
     * damaged) rather than only naming a setting they may not administer.
     */
    private function destinationFor(ReturnedQualityState $state, int $pickedWarehouseId, string $field): int
    {
        if ($state === ReturnedQualityState::Good) {
            return $pickedWarehouseId;
        }

        return $this->qualityHold->warehouseOrFail($field)->id;
    }

    /**
     * The ledger's own words for where the material went. A damaged return
     * reads as a hand-off to Quality rather than as a plain return, because
     * that is what it is — and the reference is what a person scanning the
     * movement list sees before they read any column.
     */
    private function referenceFor(ReturnedQualityState $state, string $base): string
    {
        return $state === ReturnedQualityState::Damaged
            ? $base.' — damaged, held for Quality'
            : $base;
    }

    private function attributedAsks(array $lines): array
    {
        $asks = [];

        foreach ($lines as $index => $line) {
            $lineId = isset($line['store_issue_line_id']) ? (int) $line['store_issue_line_id'] : 0;

            if ($lineId <= 0) {
                continue;
            }

            $quantity = bcadd((string) $line['quantity'], '0', 4);
            $qualityState = ReturnedQualityState::fromNullable($line['quality_state'] ?? null);

            if (! isset($asks[$lineId])) {
                $asks[$lineId] = ['index' => $index, 'quantity' => $quantity, 'quality_state' => $qualityState];

                continue;
            }

            // TWO ASKS AGAINST ONE HANDOVER LINE ARE ADDED UP — that is what
            // this merge has always done, and it is right for a quantity. It
            // is NOT right for a condition: 30 kg good and 20 kg damaged
            // against the same line is not 50 kg of anything, and picking
            // either answer would put a state on the ledger nobody wrote.
            //
            // Refused rather than resolved, because both readings are lossy
            // and the storekeeper is the one who knows. The screen cannot
            // produce this — it holds one condition per line — so this is the
            // API door being honest, not a case anybody meets at the hatch.
            if ($asks[$lineId]['quality_state'] !== $qualityState) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quality_state" => 'That store issue line is returned twice on this document in '
                        .'two different conditions. Send one line per condition, against its own store issue line, '
                        .'so the ledger records which quantity came back in which state.',
                ]);
            }

            $asks[$lineId] = [
                'index' => $asks[$lineId]['index'],
                'quantity' => bcadd($asks[$lineId]['quantity'], $quantity, 4),
                'quality_state' => $qualityState,
            ];
        }

        return $asks;
    }

    /**
     * The named handover lines, locked in id order, checked to be handovers
     * INTO the production location, and keyed by issue.
     *
     * THE WIP CHECK IS NOT DECORATION. `returnUnused` moves from the line's
     * own `to_warehouse_id`, so a line handed over into some OTHER warehouse
     * would move that warehouse's stock — under the name "production return",
     * on a screen that lists the production floor. The read side already
     * refuses to count such a line as standing (standingByItem filters on the
     * same column); this is the write side agreeing with it. A caller reaching
     * the API directly is the only way to get here.
     *
     * @param  array<int, array{index: int|string, quantity: string, quality_state: ReturnedQualityState}>  $asks
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function lockAttributedLines(array $asks, int $wipId): array
    {
        if ($asks === []) {
            return [];
        }

        $ids = array_keys($asks);
        sort($ids);

        $lines = StoreIssueLine::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'store_issue_id', 'item_id', 'from_warehouse_id', 'to_warehouse_id'])
            ->keyBy(fn ($line) => (int) $line->id);

        $grouped = [];

        foreach ($ids as $lineId) {
            $ask = $asks[$lineId];

            // REFUSE, NEVER DROP. A line id that resolves to nothing would
            // otherwise fall out of the grouping and the caller would be told
            // the return succeeded while that quantity never moved. The
            // FormRequest already refuses it; this is the half that does not
            // depend on which door the call came through.
            if (! $lines->has($lineId)) {
                throw ValidationException::withMessages([
                    "lines.{$ask['index']}.store_issue_line_id" => 'That store issue line does not exist.',
                ]);
            }

            $line = $lines->get($lineId);

            if ((int) $line->to_warehouse_id !== $wipId) {
                throw ValidationException::withMessages([
                    "lines.{$ask['index']}.store_issue_line_id" => 'That store issue did not hand material over to '
                        .'production, so it is not what is standing there. Return it against its own store issue.',
                ]);
            }

            $grouped[(int) $line->store_issue_id][] = [
                'store_issue_line_id' => $lineId,
                'item_id' => (int) $line->item_id,
                'quantity' => $ask['quantity'],
                // THE DESTINATION, DECIDED BY THE CONDITION (DEC-20260901-003).
                // A good line goes home to the store it was issued FROM, which
                // is what this door has always done and is a fact about the
                // original handover rather than this screen's to redirect. A
                // damaged one goes to quality hold instead, and refuses here —
                // inside the lock, before any material moves — when none is
                // configured.
                'to_warehouse_id' => $this->destinationFor(
                    $ask['quality_state'],
                    (int) $line->from_warehouse_id,
                    "lines.{$ask['index']}",
                ),
                // Carried from the ask, not re-read from the caller's payload:
                // by here the payload has been grouped and merged, and reading
                // it again would be reading a different line's answer.
                'quality_state' => $ask['quality_state']->value,
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
