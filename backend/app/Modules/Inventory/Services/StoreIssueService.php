<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE STORE ISSUE — and the three states it keeps apart (Phase 7.5, WS-B).
 *
 *   Store Stock ──issue──► Issued to Production ──batch──► Consumed
 *                                │
 *                                └──return──► Store Stock
 *
 * A STORE ISSUE IS NOT A CONSUMPTION. Until this phase, stock left the store
 * only at batch completion, which quietly asserted that material was
 * consumed at the moment it was handed over — it is not, and the gap between
 * the two is where a factory's material actually lives.
 *
 * THE MIDDLE STATE IS A LOCATION, NOT A FLAG. DEC-20260817-001 names the
 * logical locations Raw Material Store → Production/WIP → Finished Goods
 * Store and says Production/WIP holds material issued but not yet consumed.
 * So an issue is an ordinary signed TRANSFER pair (purpose
 * issue_to_production), a return is the mirror pair
 * (return_from_production), and the batch's consumption is the same issue it
 * has always been, now drawn from Production/WIP. `inventory:check-ledger`
 * signs by movement TYPE, so all three keep it green with no change to the
 * command at all — which is exactly why a purpose-only "issued" flag was
 * rejected: it would have been invisible to the invariant and would have
 * created no second state.
 *
 * NO CADENCE IS ASSUMED. Resin may be issued weekly, fortnightly or when the
 * pile looks low; film and cartons may be issued twice a day. Nothing here
 * is scoped to a date, a shift or a day boundary: an issue is outstanding
 * until it is returned or completed, and that is the only clock it has.
 *
 * NO MONEY. Rates, amounts and vendor identity are Owner/Accounts (FC-06);
 * this service deals in kilograms, bags, people and times. Valuation stays
 * where it always was, on the stock movement.
 */
class StoreIssueService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly ProductionWipLocationResolver $wip,
        private readonly MaterialBagIssueResolver $bags,
    ) {}

    /**
     * THE HANDOVER. Records who gave, who received, when, against which
     * request, and moves each line's quantity store → Production/WIP.
     *
     * Lines may be empty: the store may open an issue and then scan bags
     * onto it (scanBag), which is how resin is actually handed over.
     *
     * @param  array{material_request_id?: ?int, received_by?: ?int, issued_at?: ?string, notes?: ?string, lines?: array<int, array<string, mixed>>}  $data
     */
    public function issue(array $data, int $issuedBy): StoreIssue
    {
        // Resolved (and refused) BEFORE the transaction opens: a handover
        // with nowhere to hand material TO is a configuration problem, and
        // saying so first keeps a half-applied issue impossible.
        $wip = $this->wip->warehouseOrFail();

        return DB::transaction(function () use ($data, $issuedBy, $wip) {
            $issue = StoreIssue::create([
                'issue_number' => $this->nextIssueNumber(),
                'material_request_id' => $data['material_request_id'] ?? null,
                'status' => StoreIssueStatus::Issued,
                'issued_by' => $issuedBy,
                'received_by' => $data['received_by'] ?? null,
                'issued_at' => $data['issued_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] ?? [] as $index => $line) {
                $this->addLine($issue, $wip, $line, "lines.{$index}");
            }

            return $issue->fresh(['lines.item', 'bagScans']);
        });
    }

    /**
     * ONE BAG, SCANNED AT THE HANDOVER. The bag/lot resolution and every
     * refusal are the floor scanner's, shared verbatim
     * (MaterialBagIssueResolver) — a bag on QC hold is refused here exactly
     * as it is refused there.
     *
     * The kilograms move from the store the BAG ITSELF records — not from a
     * warehouse anyone typed — into Production/WIP, and the scan row keeps
     * the whole chain: bag, lot, kg, issued by, received by, when, and the
     * request line it answers. It stops there: it never says which batch
     * used the bag (FC-01).
     */
    public function scanBag(
        StoreIssue $issue,
        string $barcode,
        ?string $quantityKg,
        int $issuedBy,
        ?int $receivedBy = null,
        ?int $materialRequestLineId = null,
        ?string $notes = null,
    ): StoreIssueBagScan {
        $this->assertOpen($issue, 'scan a bag onto');
        $wip = $this->wip->warehouseOrFail();

        return DB::transaction(function () use ($issue, $barcode, $quantityKg, $issuedBy, $receivedBy, $materialRequestLineId, $notes, $wip) {
            ['bag' => $bag, 'lot' => $lot, 'quantity' => $quantity, 'remaining' => $remaining, 'full' => $full]
                = $this->bags->resolve($barcode, $quantityKg);

            $from = (int) $bag->current_warehouse_id;
            $this->assertNotIntoItself($from, $wip, 'barcode');

            // One line per material on an issue: two bags of the same resin
            // are one handover of that resin, with two scans behind it.
            $line = $issue->lines()
                ->where('item_id', $lot->item_id)
                ->where('from_warehouse_id', $from)
                ->first();

            if ($line === null) {
                $line = $issue->lines()->create([
                    'material_request_line_id' => $materialRequestLineId,
                    'item_id' => $lot->item_id,
                    'from_warehouse_id' => $from,
                    'to_warehouse_id' => $wip->id,
                    'quantity_issued' => '0.0000',
                    'quantity_returned' => '0.0000',
                    'uom' => Item::query()->find($lot->item_id)?->uom,
                ]);
            }

            $this->bags->pour($bag, $quantity, $remaining, $full);

            $line->quantity_issued = bcadd((string) $line->quantity_issued, $quantity, 4);
            $line->save();

            $this->stock->recordTransfer(
                itemId: (int) $lot->item_id,
                fromWarehouseId: $from,
                toWarehouseId: $wip->id,
                quantity: $quantity,
                reference: "Store issue {$issue->issue_number} — bag {$bag->barcode}",
                createdBy: $issuedBy,
                purpose: StockMovementPurpose::IssueToProduction,
            );

            return StoreIssueBagScan::create([
                'store_issue_id' => $issue->id,
                'store_issue_line_id' => $line->id,
                'material_request_line_id' => $materialRequestLineId ?? $line->material_request_line_id,
                'material_bag_id' => $bag->id,
                'material_lot_id' => $bag->material_lot_id,
                'quantity_kg' => $quantity,
                'issued_by' => $issuedBy,
                'received_by' => $receivedBy ?? $issue->received_by,
                'scanned_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * UNUSED MATERIAL COMING BACK. Production/WIP → the store the line came
     * out of, purpose return_from_production.
     *
     * IT NEVER INVENTS KILOGRAMS: a return larger than what is still
     * standing against that line is refused, by the line's own arithmetic,
     * before anything moves. Returning more than was issued is not a
     * generous correction, it is material appearing from nowhere.
     *
     * @param  array<int, array{store_issue_line_id: int, quantity: string}>  $lines
     */
    public function returnUnused(StoreIssue $issue, array $lines, int $recordedBy, ?string $notes = null): StoreIssue
    {
        // NO STATUS GATE HERE, deliberately. What may come back is bounded by
        // ARITHMETIC — you can return only what was issued and has not been
        // returned — and that bound already refuses a fully-returned or
        // cancelled issue, because nothing is left against it.
        //
        // A status gate would refuse one case that genuinely happens: half a
        // pallet of film coming back a day after the store closed the issue.
        // Completing moves no stock, so refusing that return would leave the
        // material standing in Production/WIP with no way to record it home.
        // A closed issue therefore accepts the return and STAYS closed — the
        // material moves, and the status keeps saying the store had stopped
        // waiting, which is what happened.

        return DB::transaction(function () use ($issue, $lines, $recordedBy, $notes) {
            foreach ($lines as $index => $requested) {
                $line = $issue->lines()->whereKey($requested['store_issue_line_id'])->lockForUpdate()->first();

                if ($line === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => 'That line does not belong to this store issue.',
                    ]);
                }

                $quantity = bcadd((string) $requested['quantity'], '0', 4);
                $outstanding = $line->quantityOutstanding();

                if (bccomp($quantity, '0', 4) !== 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => 'A return has to be more than zero.',
                    ]);
                }

                if (bccomp($quantity, $outstanding, 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => sprintf(
                            'Only %s of this line is standing with production — a return of %s would invent '
                            .'material that was never issued.',
                            $outstanding,
                            $quantity,
                        ),
                    ]);
                }

                $this->stock->recordTransfer(
                    itemId: (int) $line->item_id,
                    fromWarehouseId: (int) $line->to_warehouse_id,
                    toWarehouseId: (int) $line->from_warehouse_id,
                    quantity: $quantity,
                    reference: "Store issue {$issue->issue_number} — return",
                    notes: $notes,
                    createdBy: $recordedBy,
                    purpose: StockMovementPurpose::ReturnFromProduction,
                );

                $line->quantity_returned = bcadd((string) $line->quantity_returned, $quantity, 4);
                $line->save();
            }

            if ($issue->status->isOpen()) {
                $issue->status = $this->statusAfterReturn($issue);
                if ($issue->status === StoreIssueStatus::Returned) {
                    $issue->closed_at = now();
                    $issue->closed_by = $recordedBy;
                }
                $issue->save();
            }

            return $issue->fresh(['lines.item', 'bagScans']);
        });
    }

    /**
     * CLOSING AN ISSUE. Production used what it was given and nothing is
     * coming back.
     *
     * IT MOVES NO STOCK, and that is not an oversight: the material's
     * consumption is booked by the BATCH, from its own calculation, and
     * booking it here as well would double-count the same kilograms. This
     * records that the store is no longer waiting for a return.
     */
    public function complete(StoreIssue $issue, int $completedBy): StoreIssue
    {
        $this->assertOpen($issue, 'complete');

        $issue->status = StoreIssueStatus::Completed;
        $issue->closed_at = now();
        $issue->closed_by = $completedBy;
        $issue->save();

        return $issue->fresh(['lines.item', 'bagScans']);
    }

    /**
     * CANCELLING AN ISSUE — the whole handover reversed, with a reason.
     *
     * Only while it is untouched. Once any part has come back the reversal
     * is no longer a fact about the floor (some of it is already home), and
     * once production has consumed against it the stock is simply not there
     * to move — which the transfer's own refusal will say plainly rather
     * than quietly running the location negative.
     *
     * Nothing is deleted and no movement is rewritten: the reversal is new
     * return_from_production movements, exactly like every other correction
     * in this ledger.
     */
    public function cancel(StoreIssue $issue, string $reason, int $cancelledBy): StoreIssue
    {
        if ($issue->status !== StoreIssueStatus::Issued) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'This issue is %s and can no longer be cancelled — record what actually happened '
                    .'(a return of what came back) instead of reversing a handover that has already moved on.',
                    str_replace('_', ' ', $issue->status->value),
                ),
            ]);
        }

        return DB::transaction(function () use ($issue, $reason, $cancelledBy) {
            foreach ($issue->lines()->lockForUpdate()->get() as $line) {
                $outstanding = $line->quantityOutstanding();

                if (bccomp($outstanding, '0', 4) !== 1) {
                    continue;
                }

                $this->stock->recordTransfer(
                    itemId: (int) $line->item_id,
                    fromWarehouseId: (int) $line->to_warehouse_id,
                    toWarehouseId: (int) $line->from_warehouse_id,
                    quantity: $outstanding,
                    reference: "Store issue {$issue->issue_number} — cancelled",
                    notes: $reason,
                    createdBy: $cancelledBy,
                    purpose: StockMovementPurpose::ReturnFromProduction,
                );

                $line->quantity_returned = bcadd((string) $line->quantity_returned, $outstanding, 4);
                $line->save();
            }

            $issue->status = StoreIssueStatus::Cancelled;
            $issue->cancellation_reason = $reason;
            $issue->closed_at = now();
            $issue->closed_by = $cancelledBy;
            $issue->save();

            return $issue->fresh(['lines.item', 'bagScans']);
        });
    }

    /**
     * WHAT IS STANDING WITH PRODUCTION RIGHT NOW — read from BOTH sides and
     * shown side by side, because the two do not have to agree and the
     * difference is the thing worth seeing.
     *
     *   quantity_not_returned      the ISSUE side: what the store handed over
     *                              and has not had back. It does not fall
     *                              when a batch consumes, because the store
     *                              is not told — consumption is calculated by
     *                              the batch (FC-01).
     *   quantity_in_production_wip the LEDGER side: what the Production/WIP
     *                              balance actually holds now. This one DOES
     *                              fall as batches consume.
     *
     * Naming one of them "outstanding" and hiding the other is how the three
     * states get collapsed back into two. A completed issue is a good
     * example: the store stops waiting for a return (issue side goes to
     * zero) while kilograms are still standing in production (ledger side
     * does not) — and material that came back late, or was consumed against
     * an issue nobody closed, shows as the other kind of gap.
     *
     * An item appears when EITHER side is non-zero, so nothing standing in
     * Production/WIP can be invisible here just because its paperwork was
     * closed.
     *
     * @return array{basis: string, rows: array<int, array<string, mixed>>}
     */
    public function outstanding(): array
    {
        $wip = $this->wip->warehouse();

        $lines = StoreIssueLine::query()
            ->with('item')
            ->whereHas('storeIssue', fn ($query) => $query->whereIn('status', [
                StoreIssueStatus::Issued->value,
                StoreIssueStatus::PartiallyReturned->value,
            ]))
            ->orderBy('item_id')
            ->get();

        $byItem = [];
        foreach ($lines as $line) {
            $key = (int) $line->item_id;
            $byItem[$key] ??= $this->emptyRow($key, $line->item?->name, $line->uom ?? $line->item?->uom);

            $byItem[$key]['quantity_issued'] = bcadd($byItem[$key]['quantity_issued'], (string) $line->quantity_issued, 4);
            $byItem[$key]['quantity_returned'] = bcadd($byItem[$key]['quantity_returned'], (string) $line->quantity_returned, 4);
            $byItem[$key]['quantity_not_returned'] = bcadd($byItem[$key]['quantity_not_returned'], $line->quantityOutstanding(), 4);
        }

        if ($wip !== null) {
            $balances = StockBalance::query()
                ->with('item')
                ->where('warehouse_id', $wip->id)
                ->where('quantity', '!=', 0)
                ->orderBy('item_id')
                ->get();

            foreach ($balances as $balance) {
                $key = (int) $balance->item_id;
                $byItem[$key] ??= $this->emptyRow($key, $balance->item?->name, $balance->item?->uom);
                $byItem[$key]['quantity_in_production_wip'] = bcadd((string) $balance->quantity, '0', 4);
            }
        }

        $rows = array_values(array_filter(
            $byItem,
            fn (array $row) => bccomp($row['quantity_not_returned'], '0', 4) !== 0
                || bccomp($row['quantity_in_production_wip'], '0', 4) !== 0,
        ));

        return [
            'rows' => $rows,
            'basis' => 'quantity_not_returned is what the store handed over and has not had back; '
                .'quantity_in_production_wip is what the Production/WIP balance holds now. They differ by what '
                .'production has consumed, which the batch calculates and the store is never told.',
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRow(int $itemId, ?string $name, ?string $uom): array
    {
        return [
            'item_id' => $itemId,
            'item_name' => $name,
            'uom' => $uom,
            'quantity_issued' => '0.0000',
            'quantity_returned' => '0.0000',
            'quantity_not_returned' => '0.0000',
            'quantity_in_production_wip' => '0.0000',
            'state' => 'issued_to_production',
        ];
    }

    /**
     * HAS THE STORE EVER ISSUED THIS MATERIAL INTO PRODUCTION/WIP?
     *
     * The evidence a batch's consumption needs before it may be drawn out of
     * Production/WIP, and the reason it is asked at all: the WIP warehouse
     * row PREDATES this phase and already carries balances and movements
     * from the rehearsal data (AUDIT-WAREHOUSES-2026-08-17 §2). A rule of
     * "consume from WIP whenever WIP holds some" would therefore fire on
     * material no store issue ever put there — draining a balance nobody
     * handed over, and naming a different godown on the voucher — on the
     * first completion after deploy, with the ledger invariant green
     * throughout because the arithmetic would still add up.
     *
     * So the question is not "does WIP hold any of this" but "did THIS
     * workflow put any of it there", and only a store issue can answer yes.
     * Cancelled issues do not count: their material went back.
     */
    public function hasIssuedIntoProduction(int $itemId, int $wipWarehouseId): bool
    {
        return StoreIssueLine::query()
            ->where('item_id', $itemId)
            ->where('to_warehouse_id', $wipWarehouseId)
            ->whereHas('storeIssue', fn ($query) => $query->where('status', '!=', StoreIssueStatus::Cancelled->value))
            ->exists();
    }

    /**
     * THE TRACE BEHIND A CONSUMPTION — and it stops at the ISSUE.
     *
     * A batch consumes out of Production/WIP; this answers which store
     * issues put that material there. It is a LOCATION trace and says so in
     * its own `basis` sentence: FC-01 forbids claiming that a batch used a
     * particular bag, and DEC-20260807-007 (the common input is never
     * weighed) means a bag-level attribution would drift permanently with
     * nothing to re-anchor it. So the honest answer is "these issues put
     * this material into production before that moment", and the honest
     * answer is what is returned.
     *
     * @return array{basis: string, item_id: int, as_of: ?string, issues: array<int, array<string, mixed>>}
     */
    public function traceForItem(int $itemId, ?string $asOf = null): array
    {
        $lines = StoreIssueLine::query()
            ->with(['storeIssue.issuedBy', 'storeIssue.receivedBy', 'bagScans.bag'])
            ->where('item_id', $itemId)
            ->whereHas('storeIssue', function ($query) use ($asOf) {
                $query->where('status', '!=', StoreIssueStatus::Cancelled->value);
                if ($asOf !== null) {
                    $query->where('issued_at', '<=', $asOf);
                }
            })
            ->orderBy('id')
            ->get();

        $issues = [];
        foreach ($lines as $line) {
            $issue = $line->storeIssue;
            $issues[] = [
                'store_issue_id' => $issue->id,
                'issue_number' => $issue->issue_number,
                'material_request_id' => $issue->material_request_id,
                'issued_at' => $issue->issued_at?->toIso8601String(),
                'issued_by' => $issue->issuedBy?->name,
                'received_by' => $issue->receivedBy?->name,
                'quantity_issued' => (string) $line->quantity_issued,
                'quantity_returned' => (string) $line->quantity_returned,
                'bags' => $line->bagScans->map(fn (StoreIssueBagScan $scan) => [
                    'barcode' => $scan->bag?->barcode,
                    'material_lot_id' => $scan->material_lot_id,
                    'quantity_kg' => (string) $scan->quantity_kg,
                ])->all(),
            ];
        }

        return [
            'item_id' => $itemId,
            'as_of' => $asOf,
            'issues' => $issues,
            'basis' => 'These issues put this material into Production/WIP before that moment. A batch\'s '
                .'consumption is calculated, so this is a location trace, never a claim that a batch used a '
                .'particular bag.',
        ];
    }

    /**
     * How much of each request line is still to be issued — the store
     * queue's "remaining" column, recomputed from what was actually issued
     * rather than stored and kept in step by hand.
     *
     * Cancelled issues do not count: their material went back.
     *
     * @param  list<int>  $requestLineIds
     * @return array<int, string>
     */
    public function remainingForRequestLines(array $requestLineIds): array
    {
        if ($requestLineIds === []) {
            return [];
        }

        $lines = StoreIssueLine::query()
            ->whereIn('material_request_line_id', $requestLineIds)
            ->whereHas('storeIssue', fn ($query) => $query->where('status', '!=', StoreIssueStatus::Cancelled->value))
            ->orderBy('id')
            ->get();

        $requested = [];
        $issued = [];
        foreach ($lines as $line) {
            $key = (int) $line->material_request_line_id;
            $issued[$key] = bcadd($issued[$key] ?? '0.0000', (string) $line->quantity_issued, 4);
            if ($line->quantity_requested !== null) {
                $requested[$key] = (string) $line->quantity_requested;
            }
        }

        $remaining = [];
        foreach ($requestLineIds as $id) {
            if (! isset($requested[$id])) {
                continue;
            }
            $left = bcsub($requested[$id], $issued[$id] ?? '0.0000', 4);
            $remaining[$id] = bccomp($left, '0', 4) === -1 ? '0.0000' : $left;
        }

        return $remaining;
    }

    /**
     * THE STORE'S OWN LIST. No date default and no day scoping anywhere:
     * a resin issue raised a fortnight ago is as current as this morning's
     * film, because replenishment in this factory is consumption-driven,
     * not a daily round.
     */
    public function paginate(
        ?string $status = null,
        ?int $materialRequestId = null,
        ?int $itemId = null,
        ?string $issuedFrom = null,
        ?string $issuedTo = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return StoreIssue::query()
            ->with(['lines.item', 'issuedBy', 'receivedBy'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($materialRequestId !== null, fn ($query) => $query->where('material_request_id', $materialRequestId))
            ->when($itemId !== null, fn ($query) => $query->whereHas('lines', fn ($line) => $line->where('item_id', $itemId)))
            ->when($issuedFrom !== null, fn ($query) => $query->where('issued_at', '>=', $issuedFrom))
            ->when($issuedTo !== null, fn ($query) => $query->where('issued_at', '<=', $issuedTo))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // ---- the parts the writers share ---------------------------------------

    /**
     * @param  array<string, mixed>  $line
     */
    private function addLine(StoreIssue $issue, Warehouse $wip, array $line, string $field): StoreIssueLine
    {
        $itemId = (int) $line['item_id'];
        $quantity = bcadd((string) $line['quantity'], '0', 4);

        if (bccomp($quantity, '0', 4) !== 1) {
            throw ValidationException::withMessages([
                $field => 'An issue has to hand over more than zero.',
            ]);
        }

        $from = isset($line['from_warehouse_id'])
            ? (int) $line['from_warehouse_id']
            : $this->soleStoreHolding($itemId, $wip, $field);

        $this->assertNotIntoItself($from, $wip, $field);

        $created = $issue->lines()->create([
            'material_request_line_id' => $line['material_request_line_id'] ?? null,
            'quantity_requested' => $line['quantity_requested'] ?? null,
            'item_id' => $itemId,
            'from_warehouse_id' => $from,
            'to_warehouse_id' => $wip->id,
            'quantity_issued' => $quantity,
            'quantity_returned' => '0.0000',
            'uom' => $line['uom'] ?? Item::query()->find($itemId)?->uom,
            'notes' => $line['notes'] ?? null,
        ]);

        $this->stock->recordTransfer(
            itemId: $itemId,
            fromWarehouseId: $from,
            toWarehouseId: $wip->id,
            quantity: $quantity,
            reference: "Store issue {$issue->issue_number}",
            createdBy: (int) $issue->issued_by,
            purpose: StockMovementPurpose::IssueToProduction,
        );

        return $created;
    }

    /**
     * WHICH STORE THIS CAME OUT OF, answered by the server — the owner's
     * standing rule that nobody on the floor is asked to pick a store.
     *
     * The fact in the database decides: the one location (other than
     * Production/WIP itself) actually holding this material. Exactly one is
     * an answer; none or several is a real question, and the 422 asks it
     * rather than picking a plausible warehouse the material was never in.
     */
    private function soleStoreHolding(int $itemId, Warehouse $wip, string $field): int
    {
        $holding = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('quantity', '>', 0)
            ->limit(3)
            ->get();

        // Production/WIP is not a candidate to issue FROM — material already
        // standing with production is not the store's to hand over again —
        // but it is excluded here rather than in SQL so that "the only place
        // holding this is Production/WIP itself" gets its own plain answer
        // instead of reading as "no store has any".
        $candidates = $holding->where('warehouse_id', '!=', $wip->id)->values();

        if ($candidates->count() === 1) {
            return (int) $candidates->first()->warehouse_id;
        }

        if ($candidates->isEmpty() && $holding->isNotEmpty()) {
            $this->assertNotIntoItself($wip->id, $wip, $field);
        }

        throw ValidationException::withMessages([
            $field => $candidates->isEmpty()
                ? 'No store is recorded as holding this material, so there is nothing to issue. Check the stock '
                    .'balance, or name the store this is coming out of.'
                : 'Several stores hold this material — name the one it is coming out of.',
        ]);
    }

    private function assertNotIntoItself(int $from, Warehouse $wip, string $field): void
    {
        if ($from === $wip->id) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    '%s is already the Production/WIP location — material cannot be issued from production to itself.',
                    $wip->name,
                ),
            ]);
        }
    }

    private function assertOpen(StoreIssue $issue, string $action): void
    {
        if (! $issue->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'This issue is %s — you cannot %s it. Raise a new issue instead.',
                    str_replace('_', ' ', $issue->status->value),
                    $action,
                ),
            ]);
        }
    }

    private function statusAfterReturn(StoreIssue $issue): StoreIssueStatus
    {
        $outstanding = $issue->lines()->get()->reduce(
            fn (string $carry, StoreIssueLine $line) => bcadd($carry, $line->quantityOutstanding(), 4),
            '0.0000',
        );

        return bccomp($outstanding, '0', 4) === 1
            ? StoreIssueStatus::PartiallyReturned
            : StoreIssueStatus::Returned;
    }

    /**
     * SI-000001, SI-000002 … Sequential on the table's own count of rows,
     * inside the caller's transaction, with a uniqueness constraint behind
     * it so a race is a constraint hit rather than two issues sharing a
     * number.
     */
    private function nextIssueNumber(): string
    {
        $last = StoreIssue::query()->orderByDesc('id')->lockForUpdate()->value('issue_number');
        $next = $last !== null ? ((int) substr($last, 3)) + 1 : 1;

        return sprintf('SI-%06d', $next);
    }
}
