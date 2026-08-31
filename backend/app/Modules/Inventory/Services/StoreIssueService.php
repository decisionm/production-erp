<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
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
        private readonly MaterialRequestService $requests,
        private readonly IncomingQcHold $qcHold,
    ) {}

    /**
     * THE HANDOVER. Records who gave, who received, when, against which
     * request, and moves each line's quantity store → Production/WIP.
     *
     * Lines may be empty: the store may open an issue and then scan bags
     * onto it (scanBag), which is how resin is actually handed over.
     *
     * AND IT FULFILS THE REQUEST, in this same transaction. Every line that
     * names a request line hands its quantity to
     * MaterialRequestService::applyIssuedQuantities — the ONE writer of
     * `issued_quantity` and the only place submitted → partially_issued →
     * issued happens. Without that call the two halves of this phase never
     * meet: the store's queue goes on showing the full quantity as still
     * owed, for ever, against work that was done this morning. A refusal
     * from the request side (a line that is not on it, a request that is not
     * open to the store) rolls the whole handover back, stock included —
     * which is the point of doing it here rather than after the commit.
     *
     * @param  array{material_request_id?: ?int, received_by?: ?int, issued_at?: ?string, notes?: ?string, lines?: array<int, array<string, mixed>>}  $data
     */
    public function issue(array $data, int $issuedBy): StoreIssue
    {
        // Resolved (and refused) BEFORE the transaction opens: a handover
        // with nowhere to hand material TO is a configuration problem, and
        // saying so first keeps a half-applied issue impossible.
        $wip = $this->wip->warehouseOrFail();

        // IDEMPOTENCY — the goods-receipt pattern verbatim (GoodsReceiptService
        // has replayed on `receipt_key` since 2026_07_30_000001).
        //
        // Without it a double-tap, or a request retried after a timeout, wrote
        // a SECOND handover: two issue numbers and two transfer pairs moving
        // material into Production/WIP twice. Nothing in the ledger looked
        // wrong afterwards — both issues were real and every balance stayed
        // consistent — the store had simply handed over twice what it believed.
        $issueKey = isset($data['issue_key']) ? trim((string) $data['issue_key']) : null;
        $issueKey = ($issueKey === null || $issueKey === '') ? null : $issueKey;
        $payloadHash = $issueKey !== null ? $this->payloadHash($data) : null;

        // The cheap read first: the ordinary replay is a retry long after the
        // winner committed, and it should not have to open a transaction.
        if ($issueKey !== null) {
            $existing = StoreIssue::query()->where('issue_key', $issueKey)->first();

            if ($existing !== null) {
                return $this->replay($existing, $payloadHash);
            }
        }

        try {
            return DB::transaction(function () use ($data, $issuedBy, $wip, $issueKey, $payloadHash) {
                // Read again under the row lock: two requests can both miss
                // the read above, and everything short of a true constraint
                // race is settled here.
                if ($issueKey !== null) {
                    $existing = StoreIssue::query()
                        ->where('issue_key', $issueKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $this->replay($existing, $payloadHash);
                    }
                }

                $issue = StoreIssue::create([
                    'issue_number' => $this->nextIssueNumber(),
                    'issue_key' => $issueKey,
                    'issue_payload_hash' => $payloadHash,
                    'material_request_id' => $data['material_request_id'] ?? null,
                    'status' => StoreIssueStatus::Issued,
                    'issued_by' => $issuedBy,
                    'received_by' => $data['received_by'] ?? null,
                    'issued_at' => $data['issued_at'] ?? now(),
                    'notes' => $data['notes'] ?? null,
                ]);

                $deltas = [];

                foreach ($data['lines'] ?? [] as $index => $line) {
                    $created = $this->addLine($issue, $wip, $line, "lines.{$index}");

                    if ($created->material_request_line_id !== null) {
                        $key = (int) $created->material_request_line_id;
                        $deltas[$key] = bcadd($deltas[$key] ?? '0.0000', (string) $created->quantity_issued, 4);
                    }
                }

                $this->fulfilRequest($issue, $deltas);

                return $issue->fresh(['lines.item', 'bagScans']);
            });
        } catch (QueryException $exception) {
            // A genuine race: both transactions missed both reads, the unique
            // index let one commit and rolled the other back BEFORE it could
            // move stock a second time. Return the winner rather than an error
            // — from the caller's side the handover did happen.
            if ($issueKey !== null) {
                $existing = StoreIssue::query()->where('issue_key', $issueKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $payloadHash);
                }
            }

            throw $exception;
        }
    }

    /**
     * The same key twice. Identical data replays the original handover;
     * DIFFERENT data is refused.
     *
     * The refusal is the load-bearing half. Without the payload check, a
     * retry carrying CORRECTED quantities would return the first issue and
     * report success while writing nothing — and the store would believe the
     * correction had been recorded. Better to say plainly that the key is
     * spent.
     */
    private function replay(StoreIssue $issue, ?string $payloadHash): StoreIssue
    {
        if ($issue->issue_payload_hash !== $payloadHash) {
            throw ValidationException::withMessages([
                'issue_key' => 'This issue key was already used for a different handover. Generate a new key.',
            ]);
        }

        return $issue->fresh(['lines.item', 'bagScans']);
    }

    /**
     * A stable fingerprint of the handover, so "the same request" means the
     * same MATERIAL and QUANTITIES rather than the same byte order — a client
     * that serialises its JSON keys differently on a retry is still retrying.
     */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
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
     *
     * IT FULFILS THE REQUEST TOO, exactly as issue() does and for the same
     * reason. This is the resin path — the store opens an empty issue and
     * scans bags onto it — so wiring only issue() would leave the queue
     * stale for the one material that actually moves that way.
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

            // NO CUSTODY WRITE HERE, and the attempt is worth recording.
            //
            // Setting $bag->current_warehouse_id to Production/WIP looks
            // obviously right and broke two things at once:
            //
            //  · $from above is this same column, so the NEXT partial scan of a
            //    part-emptied bag read WIP as its source and was refused with
            //    "already the Production/WIP location". A storekeeper weighing
            //    20 kg off a 50 kg bag could never scan the rest of it again.
            //  · A return names an issue LINE, never a barcode, so nothing can
            //    move the bag back. The bag would claim to stand on the floor
            //    for ever after its kilograms had gone home.
            //
            // Before, the column was uniformly stale and KNOWN to be. With the
            // write it became confidently wrong, which is worse for a custody
            // claim. What actually moved is recorded — and provable — as the
            // StoreIssueBagScan rows created below: this bag, this issue, this
            // quantity, this moment. That is custody provenance without
            // pretending to a location the system cannot maintain. Q57.

            $line->quantity_issued = bcadd((string) $line->quantity_issued, $quantity, 4);
            $line->save();

            // THE REQUEST LINE THIS SCAN NAMED, not the one the issue line
            // happens to carry. The issue line is found by (material, source
            // store), so two bags of the same resin share one line — and its
            // material_request_line_id is whatever the FIRST scan set. A
            // second bag scanned against a different request line would
            // otherwise be credited to the first line, and the store's queue
            // would show one line over-fulfilled and the other untouched.
            $creditLineId = $materialRequestLineId ?? $line->material_request_line_id;

            if ($creditLineId !== null) {
                $this->fulfilRequest($issue, [(int) $creditLineId => $quantity]);
            }

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
     * AND IT NEVER TAKES ANOTHER ISSUE'S MATERIAL. The line's own arithmetic
     * is not enough on its own: once a batch has consumed against this
     * issue, part of what the line says it handed over is GONE, and a return
     * of the full line would come out of whatever else happens to be
     * standing in Production/WIP — another issue's kilograms — with the
     * transfer succeeding quietly because the pooled balance covered it. So
     * a second bound applies: unconsumedBudgets(). See its docblock for the
     * rule and why it is the conservative one.
     *
     * IT REFUSES, IT DOES NOT SILENTLY CAP. The storekeeper typed a figure;
     * writing a smaller one under it would record a return that did not
     * happen, and the difference would then be missing from both sides. The
     * refusal names what is actually standing, so the next attempt is a
     * number a person chose.
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
            // Read ONCE, before the first line moves, and spent down as the
            // lines are honoured: two lines of one material on the same
            // issue must not each be told the whole budget is theirs.
            $budgets = $this->unconsumedBudgets($issue);

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

                $budgetKey = $this->budgetKey($line);
                $unconsumed = $budgets[$budgetKey] ?? '0.0000';

                if (bccomp($quantity, $unconsumed, 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => $this->consumedAwayMessage($issue, $outstanding, $unconsumed, $quantity, 'return'),
                    ]);
                }

                $budgets[$budgetKey] = bcsub($unconsumed, $quantity, 4);

                $this->stock->recordTransfer(
                    itemId: (int) $line->item_id,
                    fromWarehouseId: (int) $line->to_warehouse_id,
                    toWarehouseId: (int) $line->from_warehouse_id,
                    quantity: $quantity,
                    reference: "Store issue {$issue->issue_number} — return",
                    notes: $notes,
                    createdBy: $recordedBy,
                    purpose: StockMovementPurpose::ReturnFromProduction,
                    // PER LINE, NOT PER RETURN. One trip back to the hatch can
                    // carry a clean sack of masterbatch and a wet one, and a
                    // single state for the whole document would have to pick
                    // one of them and be wrong about the other. A caller that
                    // says nothing means `good` — see ReturnedQualityState.
                    qualityState: ReturnedQualityState::fromNullable($requested['quality_state'] ?? null),
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
     * once production has CONSUMED against it the kilograms are gone — so
     * the cancellation is refused outright, naming what is still standing.
     *
     * WHY REFUSE RATHER THAN CANCEL WHAT IS LEFT. A cancellation asserts
     * that the whole handover never effectively happened. Once a batch has
     * burnt part of it that assertion is false, and a "cancellation" that
     * silently reversed only the remainder would write a smaller number than
     * the document claims and leave the rest unaccounted on both sides. What
     * actually happened — some was used, some is coming back — is a RETURN,
     * and the refusal says so. It never leans on the transfer's own
     * insufficient-stock refusal either: that one only fires when the whole
     * POOLED Production/WIP balance is short, so with a second issue
     * standing beside this one it would succeed and take that issue's
     * material instead.
     *
     * Nothing is deleted and no movement is rewritten: the reversal is new
     * return_from_production movements, exactly like every other correction
     * in this ledger. The REQUEST behind it is given its remainder back at
     * the same time (reverseIssuedQuantities), because a cancelled handover
     * is owed again.
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
            $budgets = $this->unconsumedBudgets($issue);
            $deltas = [];

            foreach ($issue->lines()->lockForUpdate()->get() as $line) {
                $outstanding = $line->quantityOutstanding();

                if (bccomp($outstanding, '0', 4) !== 1) {
                    continue;
                }

                $budgetKey = $this->budgetKey($line);
                $unconsumed = $budgets[$budgetKey] ?? '0.0000';

                if (bccomp($outstanding, $unconsumed, 4) === 1) {
                    throw ValidationException::withMessages([
                        'status' => $this->consumedAwayMessage($issue, $outstanding, $unconsumed, $outstanding, 'cancellation'),
                    ]);
                }

                $budgets[$budgetKey] = bcsub($unconsumed, $outstanding, 4);

                if ($line->material_request_line_id !== null) {
                    $key = (int) $line->material_request_line_id;
                    $deltas[$key] = bcsub($deltas[$key] ?? '0.0000', (string) $line->quantity_issued, 4);
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

            $this->unfulfilRequest($issue, $deltas);

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
     * IS THE STORE STILL WAITING ON MATERIAL IT HANDED OVER? — an OPEN issue
     * (issued or partly returned) with kilograms it has not had back.
     *
     * The second half of "is Production/WIP still in play for this material"
     * (FactoryWarehouseResolver::consumptionSource), and the half the stock
     * balance cannot answer. A batch that consumed everything standing
     * leaves the WIP balance at zero while the store's paperwork still says
     * the kilograms are out there; the NEXT batch's consumption belongs
     * against that open handover, driving Production/WIP negative and
     * recording the shortfall loudly, rather than quietly falling back to
     * the store and taking material nobody ever issued.
     *
     * A CLOSED issue does not keep it in play, deliberately. Once the store
     * has completed or been fully returned and the balance is flat, nothing
     * is standing in production, and consumption goes back to being answered
     * exactly as it was before this phase existed.
     */
    public function hasMaterialStandingInProduction(int $itemId, int $wipWarehouseId): bool
    {
        return StoreIssueLine::query()
            ->where('item_id', $itemId)
            ->where('to_warehouse_id', $wipWarehouseId)
            ->whereColumn('quantity_issued', '>', 'quantity_returned')
            ->whereHas('storeIssue', fn ($query) => $query->whereIn('status', [
                StoreIssueStatus::Issued->value,
                StoreIssueStatus::PartiallyReturned->value,
            ]))
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

    // ---- the request behind the handover -----------------------------------

    /**
     * Hand the deltas to the request's ONE writer, inside the caller's
     * transaction. Silent when there is nothing to tell it: an issue against
     * a verbal ask (no request), a line that answers no request line, or a
     * request id that names no row.
     *
     * @param  array<int, string>  $deltas  request line id → quantity handed over now
     */
    private function fulfilRequest(StoreIssue $issue, array $deltas): void
    {
        $request = $this->requestBehind($issue, $deltas);

        if ($request !== null) {
            $this->requests->applyIssuedQuantities($request, $deltas);
        }
    }

    /**
     * The mirror, for a cancelled handover. Deltas are negative.
     *
     * @param  array<int, string>  $deltas  request line id → quantity taken back
     */
    private function unfulfilRequest(StoreIssue $issue, array $deltas): void
    {
        $request = $this->requestBehind($issue, $deltas);

        if ($request !== null) {
            $this->requests->reverseIssuedQuantities($request, $deltas);
        }
    }

    /** @param  array<int, string>  $deltas */
    private function requestBehind(StoreIssue $issue, array $deltas): ?MaterialRequest
    {
        if ($issue->material_request_id === null || $deltas === []) {
            return null;
        }

        return MaterialRequest::query()->find((int) $issue->material_request_id);
    }

    // ---- what is still standing, unconsumed, against THIS issue ------------

    /**
     * HOW MUCH OF THIS ISSUE IS STILL UNCONSUMED — the ceiling on any
     * reversal of it, per material and per Production/WIP location.
     *
     *   budget = (what this issue handed over and has not had back)
     *            − (every consumption drawn out of that location, for that
     *               material, since this issue was made)
     *
     * THE SECOND TERM IS CHARGED IN FULL TO THIS ISSUE, and that is the
     * deliberate, conservative half. A batch's consumption is CALCULATED
     * (FC-01, DEC-20260807-007): nothing in this factory can say which
     * handover the kilograms a machine burnt came out of, and a rule that
     * split them between issues would be inventing an attribution. So the
     * issue being reversed is assumed to be the one that was eaten. The
     * worst that can happen is a refusal on an issue that was in fact
     * untouched — and a refusal naming real figures is a question a person
     * can answer, where a reversal that quietly drained the issue standing
     * next to it is a wrong number nobody would ever see. Whether the
     * factory would rather cap than refuse here is an OWNER question, and it
     * is recorded as one.
     *
     * "SINCE THIS ISSUE WAS MADE" is the one thing that keeps it from being
     * gratuitous: material handed over at 14:00 cannot have been consumed at
     * 09:00, so consumption older than the issue is not charged to it. That
     * also keeps the pre-phase residue on the WIP row — which the warehouse
     * audit found and hasIssuedIntoProduction() already guards against —
     * from eating a budget it has nothing to do with.
     *
     * TWO ASSUMPTIONS IN THAT COMPARISON, both load-bearing, both currently
     * true and worth re-checking if either side changes. (1) A consumption's
     * movement_date is the system clock: completeBatch is the only caller
     * that books purpose Consumption and it supplies no date. (2) An issue's
     * issued_at is normally the system clock too, but the API accepts one —
     * a handover BACKDATED by a week is charged every consumption since that
     * week began, and would be refused a reversal it should have. The bound
     * is inclusive (>=), so an issue and a consumption recorded in the same
     * second are charged to each other: the conservative direction, a
     * refusal rather than a wrong reversal, but the refusal message cannot
     * explain it and this note is where it is written down.
     *
     * Floored at zero and never above what the line itself is standing: a
     * budget is a ceiling, never a permission to move more.
     *
     * @return array<string, string> "item@warehouse" → kilograms still unconsumed
     */
    private function unconsumedBudgets(StoreIssue $issue): array
    {
        $standing = [];
        foreach ($issue->lines()->get() as $line) {
            $key = $this->budgetKey($line);
            $standing[$key] = bcadd($standing[$key] ?? '0.0000', $line->quantityOutstanding(), 4);
        }

        $budgets = [];
        foreach ($standing as $key => $outstanding) {
            [$itemId, $warehouseId] = array_map('intval', explode('@', $key));

            $left = bcsub($outstanding, $this->consumedSince($itemId, $warehouseId, $issue->issued_at), 4);
            $budgets[$key] = bccomp($left, '0', 4) === 1 ? $left : '0.0000';
        }

        return $budgets;
    }

    private function budgetKey(StoreIssueLine $line): string
    {
        return ((int) $line->item_id).'@'.((int) $line->to_warehouse_id);
    }

    /**
     * Consumption drawn out of a Production/WIP location for one material
     * since a moment — read from the LEDGER's own consumption movements, not
     * from the stock balance.
     *
     * The balance is the wrong source and dangerously so: the WIP row
     * predates this phase and carries rehearsal receipts, so a reversal
     * bounded by "what the balance holds" could draw on kilograms no store
     * issue ever put there.
     *
     * Summed in bcmath rather than SQL SUM(): SQLite hands a DECIMAL sum
     * back as a float, and a kilogram figure that has been through a float
     * is not one this codebase will print.
     */
    private function consumedSince(int $itemId, int $warehouseId, mixed $since): string
    {
        $total = '0.0000';

        StockMovement::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('type', StockMovementType::Issue->value)
            ->where('purpose', StockMovementPurpose::Consumption->value)
            ->when($since !== null, fn ($query) => $query->where('movement_date', '>=', $since))
            ->orderBy('id')
            ->get(['quantity'])
            ->each(function ($movement) use (&$total) {
                $total = bcadd($total, (string) $movement->quantity, 4);
            });

        return $total;
    }

    /** The one sentence both reversals refuse with — figures, never a guess. */
    private function consumedAwayMessage(
        StoreIssue $issue,
        string $outstanding,
        string $unconsumed,
        string $asked,
        string $action,
    ): string {
        return sprintf(
            'Production has already consumed against store issue %s: of the %s this line handed over and has not '
            .'had back, only %s is still standing unconsumed in Production/WIP, so a %s of %s would take material '
            .'belonging to another issue. Record a return of what actually came back (at most %s); if more than '
            .'that has genuinely come home, the batch consumption figures are what need correcting.',
            $issue->issue_number,
            $outstanding,
            $unconsumed,
            $action,
            $asked,
            $unconsumed,
        );
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
        $this->assertNotHeldByIncomingQc($itemId, $from, $quantity, $field);

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
     * THE ARRIVAL HOLD, SAID IN THIS LINE'S OWN WORDS.
     *
     * The RULE is not here. It is enforced for every outflow at
     * StockMovementService::decrementBalance, which this handover's transfer
     * goes through like every other writer — so removing this method would
     * not reopen the door. What it is for is the SENTENCE: a typed line is
     * one of several on a form, and a storekeeper needs to be told which
     * line is the problem and how many kilograms of it may go. The central
     * refusal is a 422 about an item and a store; this one is a 422 about
     * `lines.2`, raised BEFORE anything is written, so the whole handover is
     * refused with the form still readable rather than half-built and rolled
     * back.
     *
     * The arithmetic is the shared one (IncomingQcHold) to the character —
     * two readings of "how much is held" that could drift apart is exactly
     * the bug this seam is closing. Same lock, same order: bags, then
     * balance.
     *
     * What counts as held, what does not, and why, is documented once, on
     * IncomingQcHold.
     *
     * ONE ORDERING GAP, NAMED RATHER THAN LEFT TO BE FOUND: a multi-line
     * handover takes one bag-lock set PER LINE, in the order the lines
     * arrived on the form. Two multi-line issues submitted at the same
     * moment with their materials in opposite order could each hold the bag
     * set the other is waiting for. Sorting the lines by item_id before the
     * loop would remove it; that is a change to how a handover is written,
     * not to the hold, so it is disclosed here rather than made in the same
     * pass as the hold itself.
     */
    private function assertNotHeldByIncomingQc(int $itemId, int $fromWarehouseId, string $quantity, string $field): void
    {
        [$held, $bagCount] = $this->qcHold->lockAndSum($itemId, $fromWarehouseId);

        if (bccomp($held, '0', 4) !== 1) {
            return;
        }

        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $fromWarehouseId)
            ->lockForUpdate()
            ->value('quantity');

        $available = $this->qcHold->available((string) ($balance ?? '0'), $held);

        if (bccomp($quantity, $available, 4) !== 1) {
            return;
        }

        $plain = fn (string $number): string => rtrim(rtrim($number, '0'), '.') ?: '0';

        throw ValidationException::withMessages([
            $field => sprintf(
                'Only %s of %s can be handed over from this store — %s of what the balance shows is in %d bag(s) '
                .'still waiting for incoming QC, and material on hold is not production\'s yet. Quality has to '
                .'release the arrival first; released bags can also be handed over by scanning them.',
                $plain($available),
                Item::query()->find($itemId)?->name ?? 'this material',
                $plain($held),
                $bagCount,
            ),
        ]);
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
