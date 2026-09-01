<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WHAT QUALITY DOES WITH MATERIAL THAT CAME BACK DAMAGED (DEC-20260901-003).
 *
 * The return door put it in the quality-hold location instead of the store,
 * so it is already out of issuable stock and nothing here has to keep it
 * out. This class is the other end: the two things Quality may do once they
 * have looked at it.
 *
 *   CONFIRM DAMAGE → the quantity is SCRAPPED. It leaves stock through an
 *   issue, exactly the route incoming QC already uses for material it
 *   rejects, carrying purpose `scrap` so the ledger says what happened.
 *   Nothing changes the item's identity and nothing books inward against a
 *   Scrap master: this ERP has no scrap-item mapping for a purchased input,
 *   and DEC-20260901-002 leaves which Scrap item undecided. Inventing one
 *   would put a fabricated Tally name into the books.
 *
 *   RELEASE → the quantity goes to the store as usable stock, because
 *   Quality looked and it was not damaged after all.
 *
 * WHY RELEASE EXISTS AT ALL, since the owner did not ask for it in so many
 * words: the rule is that damaged material must never go back to usable
 * stock DIRECTLY, and a storekeeper who ticks the wrong box at the end of a
 * shift has to have a way back. Without it a mis-tick strands good material
 * in a location nothing can draw from — the exact failure the production
 * return door was built to fix. It is recorded as the agent's reading in
 * DEC-20260901-003 so the owner can withdraw it in one line.
 *
 * NEITHER ACTION POSTS TO TALLY, and that is not an omission. What Tally
 * should receive when returned material is scrapped is expressly undecided
 * (DEC-20260901-003, and the sibling clause DEC-20260825-001 already leaves
 * open for QA rejection at arrival). The stock fact is recorded; the voucher
 * waits for the answer.
 *
 * QUANTITY, NOT BAGS. Material comes back off the floor as a figure — the
 * return door has no bag scan behind it — so the hold is a balance in a
 * location, and so is the disposition. That is also why this does not reuse
 * IncomingQcHold, which reads bag rows and answers a different question
 * about a different population.
 */
class ReturnedMaterialQualityService
{
    public function __construct(
        private readonly QualityHoldLocationResolver $qualityHold,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * What is standing in quality hold right now, one row per material.
     *
     * ZERO AND NEGATIVE ROWS ARE EXCLUDED. A row that has been fully disposed
     * of is finished with, and a negative balance in a hold is a discrepancy
     * rather than something Quality can act on — offering either as a line to
     * scrap invites a person to act on a number that is not there.
     *
     * `withTrashed` on the item, matching the stock list: the material is
     * standing there whatever the master's state, and a retired item whose
     * name would not resolve is exactly the population this hold catches.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function standing(): Collection
    {
        $warehouse = $this->qualityHold->warehouse();

        if ($warehouse === null) {
            return collect();
        }

        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with(['item' => fn ($query) => $query->withTrashed()])
            ->get()
            ->map(fn (StockBalance $balance): array => [
                'item_id' => (int) $balance->item_id,
                'item_name' => $balance->item?->name,
                'item_sku' => $balance->item?->sku,
                'uom' => $balance->item?->uom,
                'item_is_active' => (bool) ($balance->item?->is_active ?? false),
                'quantity' => bcadd((string) $balance->quantity, '0', 4),
                'warehouse_id' => $warehouse->id,
            ])
            ->values();
    }

    /**
     * Quality confirmed the damage: the quantity is scrapped and leaves stock.
     *
     * @param  list<array{item_id: int, quantity: string}>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    public function confirmDamage(array $lines, int $recordedBy, ?string $notes = null): Collection
    {
        return $this->dispose(
            $lines,
            $recordedBy,
            $notes,
            'confirm',
            function (int $itemId, string $quantity, int $holdId, ?string $notes) use ($recordedBy): void {
                $this->stock->recordIssue(
                    itemId: $itemId,
                    warehouseId: $holdId,
                    quantity: $quantity,
                    reference: 'Scrap — damage confirmed by Quality',
                    notes: $notes,
                    createdBy: $recordedBy,
                    purpose: StockMovementPurpose::Scrap,
                );
            },
        );
    }

    /**
     * Quality looked and it is not damaged: the quantity goes to the store as
     * usable stock.
     *
     * @param  list<array{item_id: int, quantity: string}>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    public function release(array $lines, int $toWarehouseId, int $recordedBy, ?string $notes = null): Collection
    {
        $holdId = $this->qualityHold->warehouseOrFail()->id;

        // RELEASING INTO THE HOLD ITSELF IS NOT A RELEASE. Refused here, and
        // in these words, rather than left to recordTransfer's same-warehouse
        // message, which is written for a caller with a bug rather than for a
        // person who picked the wrong row from a dropdown.
        if ($toWarehouseId === $holdId) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'Released material has to go to a store. That is the quality-hold location '
                    .'itself, which is where it already is.',
            ]);
        }

        return $this->dispose(
            $lines,
            $recordedBy,
            $notes,
            'release',
            function (int $itemId, string $quantity, int $hold, ?string $notes) use ($toWarehouseId, $recordedBy): void {
                $this->stock->recordTransfer(
                    itemId: $itemId,
                    fromWarehouseId: $hold,
                    toWarehouseId: $toWarehouseId,
                    quantity: $quantity,
                    reference: 'Released from quality hold — not damaged',
                    notes: $notes,
                    createdBy: $recordedBy,
                );
            },
        );
    }

    /**
     * The half both actions share: resolve the hold, bound each line by what
     * is actually standing, and move in item order.
     *
     * IN ITEM ORDER, NOT THE ORDER THEY WERE TYPED — the same rule the return
     * door follows and for the same reason: each move locks that material's
     * balances, so two dispositions sent at once in opposite orders would
     * deadlock and InnoDB would kill one.
     *
     * THE BUDGET IS READ ONCE AND SPENT DOWN, so two lines naming the same
     * material cannot each be told the whole hold is theirs. The database's
     * own negative-stock refusal would catch the overdraw, but it would catch
     * it with a message about balances rather than about this hold.
     *
     * @param  list<array{item_id: int, quantity: string}>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function dispose(array $lines, int $recordedBy, ?string $notes, string $verb, callable $move): Collection
    {
        $hold = $this->qualityHold->warehouseOrFail();

        return DB::transaction(function () use ($lines, $hold, $notes, $verb, $move): Collection {
            $standing = $this->standing()->keyBy('item_id');
            $budgets = [];
            $moved = collect();

            $ordered = $lines;
            usort($ordered, fn (array $a, array $b): int => (int) $a['item_id'] <=> (int) $b['item_id']);

            foreach ($ordered as $line) {
                $itemId = (int) $line['item_id'];
                $quantity = bcadd((string) $line['quantity'], '0', 4);

                // The index the CALLER sent, not the order things moved in —
                // a person reading a refusal is looking at their own screen.
                $index = $this->indexOf($lines, $line);

                if (bccomp($quantity, '0', 4) !== 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => 'A quantity has to be more than zero.',
                    ]);
                }

                $budgets[$itemId] ??= $standing[$itemId]['quantity'] ?? '0.0000';

                if (bccomp($quantity, $budgets[$itemId], 4) === 1) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => sprintf(
                            'Only %s of %s is standing in quality hold, so %s cannot be %sed.',
                            rtrim(rtrim($budgets[$itemId], '0'), '.') ?: '0',
                            Item::withTrashed()->whereKey($itemId)->value('name') ?? "item #{$itemId}",
                            rtrim(rtrim($quantity, '0'), '.') ?: '0',
                            $verb,
                        ),
                    ]);
                }

                $budgets[$itemId] = bcsub($budgets[$itemId], $quantity, 4);

                $move($itemId, $quantity, $hold->id, $notes);

                $moved->push(['item_id' => $itemId, 'quantity' => $quantity]);
            }

            return $moved;
        });
    }

    /** @param  list<array{item_id: int, quantity: string}>  $lines */
    private function indexOf(array $lines, array $line): int|string
    {
        foreach ($lines as $index => $candidate) {
            if ($candidate === $line) {
                return $index;
            }
        }

        return 0;
    }
}
