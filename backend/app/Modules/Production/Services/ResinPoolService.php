<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ResinPoolBalance;

/**
 * THE COMMON RESIN POOL'S ARITHMETIC — fold on load, draw on consumption,
 * give back on a correction. One weighted average per EXACT material.
 *
 * ========================= WHAT THIS REPLACED =========================
 *
 * Bag-load FIFO layers, and the claim that went with them: that a batch's
 * resin could be traced to the specific bags a specific machine was loaded
 * with. The owner's correction (2-Aug) ended that claim — the factory has
 * ONE common resin input point for all machines, a bag is never assigned or
 * scanned to a machine, so there is no physical bag-to-batch path to trace.
 *
 * What is left is an ACCOUNTING ALLOCATION, and it is labelled as one
 * everywhere it surfaces. Every kg entering the common input joins the pool
 * for its material; the pool carries one rate; a batch draws at that rate.
 * That is a real, defensible, auditable costing basis. It is not
 * traceability, and nothing in this codebase may present it as such.
 *
 * ========================== WHY A ROW LOCK ==========================
 *
 * The pool is GLOBAL PER MATERIAL, which is exactly what makes it
 * contention-prone: every machine in the factory completing a batch draws
 * from the same row. Two completions reading the same committed average and
 * both drawing it would charge the same kilogram twice and leave the pool
 * overstated, invisibly. So every mutation goes through lockRow(), and
 * callers that touch several materials lock in ASCENDING ITEM ID ORDER —
 * a fixed order is what stops two allocators deadlocking on the same pair
 * of pools in opposite directions.
 *
 * (The previous design locked the WORK CENTER row, which was right when
 * layers belonged to a machine and is wrong now: two different machines
 * would take two different locks and both draw the same pool.)
 *
 * ====================== ZERO IS NOT A PRICE ======================
 *
 * A lot with no recorded rate folds into unpriced_kg and is kept OUT of the
 * average. Averaging in a rate nobody knows would price that material at
 * whatever the rest of the pool happened to cost — a confident number with
 * nothing behind it. The unpriced kg are counted honestly instead, and the
 * consumption they should have covered falls to the labelled stock-average
 * fallback in BagCostAllocationService, which is the signal finance needs.
 *
 * unpriced_kg is a RUNNING TOTAL of unpriced material folded in, not a
 * drawable balance — material with no rate cannot be drawn AT a rate, so
 * nothing here decrements it. Stated plainly so nobody later "fixes" it into
 * a balance and quietly starts pricing unpriced material.
 *
 * ====================== ALL FIGURES ARE STRINGS ======================
 *
 * bcmath at 4dp throughout, the way the rest of the shift engine speaks
 * about kg and money. A stock or money figure that has been through a float
 * is not one this codebase will print.
 */
class ResinPoolService
{
    private const SCALE = 4;

    /**
     * MATERIAL ENTERING THE COMMON INPUT. Same moving-average arithmetic as
     * StockMovementService::incrementBalance — deliberately the same shape,
     * so the pool valuation and the stock valuation can be read side by side
     * without anyone having to work out whether they mean the same thing by
     * "average". They are still two separate mechanisms: nothing here writes
     * a stock movement or touches stock_balances.average_cost.
     *
     * A null (or zero, or non-numeric) rate is NOT a price — it folds into
     * unpriced_kg and leaves the average exactly where it was.
     */
    public function fold(int $itemId, string $quantityKg, ?string $ratePerKg): ResinPoolBalance
    {
        $quantity = $this->decimal($quantityKg);

        $pool = $this->lockRow($itemId);

        if (bccomp($quantity, '0', self::SCALE) !== 1) {
            return $pool;
        }

        if (! $this->isPrice($ratePerKg)) {
            $pool->update([
                'unpriced_kg' => bcadd($this->decimal($pool->unpriced_kg), $quantity, self::SCALE),
            ]);

            return $pool->refresh();
        }

        $rate = $this->decimal((string) $ratePerKg);
        $currentQuantity = $this->decimal($pool->quantity_kg);
        $newQuantity = bcadd($currentQuantity, $quantity, self::SCALE);

        $pool->update([
            'quantity_kg' => $newQuantity,
            'avg_rate_per_kg' => $this->blend($currentQuantity, $this->decimal($pool->avg_rate_per_kg), $quantity, $rate, $newQuantity),
        ]);

        return $pool->refresh();
    }

    /**
     * A BATCH'S CONSUMPTION, DRAWN AT THE POOL'S CURRENT AVERAGE.
     *
     * Draws as much as the PRICED pool can cover and reports what it could
     * not. The average is unchanged by a draw — taking kg out of a pool does
     * not change what the remaining kg cost — so the frozen rate on the
     * allocation row and the pool's rate afterwards are the same number, and
     * a reversal can give the kg back at that same rate without inventing
     * anything.
     *
     * @return array{0: string, 1: ?string, 2: string} [drawn kg, the rate it
     *                                                 was drawn at (null when nothing was drawn), kg left uncovered]
     */
    public function draw(int $itemId, string $quantityKg): array
    {
        $wanted = $this->decimal($quantityKg);

        if (bccomp($wanted, '0', self::SCALE) !== 1) {
            return ['0.0000', null, '0.0000'];
        }

        $pool = $this->lockRow($itemId);

        $available = $this->decimal($pool->quantity_kg);
        $rate = $this->decimal($pool->avg_rate_per_kg);

        // An empty (or unpriced-only) pool draws nothing and says so — the
        // whole quantity comes back as uncovered and the caller prices it at
        // the labelled stock-average fallback.
        if (bccomp($available, '0', self::SCALE) !== 1 || ! $this->isPrice($rate)) {
            return ['0.0000', null, $wanted];
        }

        $drawn = bccomp($available, $wanted, self::SCALE) === 1 ? $wanted : $available;

        $pool->update(['quantity_kg' => bcsub($available, $drawn, self::SCALE)]);

        return [$drawn, $rate, bcsub($wanted, $drawn, self::SCALE)];
    }

    /**
     * A CORRECTION GIVING KG BACK, AT THE RATE THEY WERE TAKEN AT.
     *
     * The mirror of draw(), and the same reversal semantics the stock ledger
     * uses: what comes back comes back at its OWN recorded rate, not at
     * whatever the pool averages today. Folding a reversal at today's average
     * would let an amendment quietly re-price material it never touched.
     *
     * So the pool after "wrong completion, then amendment" holds exactly what
     * a never-wrong world would have left in it, as long as nothing else
     * moved the pool in between — and when something did, the arithmetic is
     * still right, it simply reflects that the other movements really
     * happened.
     */
    public function restore(int $itemId, string $quantityKg, ?string $ratePerKg): ResinPoolBalance
    {
        return $this->fold($itemId, $quantityKg, $ratePerKg);
    }

    /**
     * The pool's current weighted average, or null when there is no priced
     * material in it. Read-only, unlocked — a quote is a snapshot, not a
     * mutation, and locking the whole factory's pool to render an estimate
     * would be a real cost for no correctness.
     */
    public function currentAverage(int $itemId): ?string
    {
        $pool = ResinPoolBalance::query()->where('item_id', $itemId)->first();

        if ($pool === null || bccomp($this->decimal($pool->quantity_kg), '0', self::SCALE) !== 1) {
            return null;
        }

        $rate = $this->decimal($pool->avg_rate_per_kg);

        return $this->isPrice($rate) ? $rate : null;
    }

    /**
     * Whether this material has a pool at all — i.e. whether anything has
     * ever been loaded into the common input for it.
     *
     * THIS IS THE DISCRIMINATOR that decides which consumption lines are
     * pool-costed and which are priced by materialCost() as ordinary
     * material. The LOAD TRAIL decides, and nothing else does: this module
     * refuses to identify materials by name (see
     * ShiftProductionEntryService::refuseStaleMaterialLines for why), so
     * material that has been loaded into the common input has a pool and is
     * costed from it, and material that has not does not and is not.
     */
    public function exists(int $itemId): bool
    {
        return ResinPoolBalance::query()->where('item_id', $itemId)->exists();
    }

    /**
     * The pool row for this material, locked for the rest of the calling
     * transaction, created at zero if it does not exist yet.
     *
     * Same firstOrCreate-under-lock shape as
     * StockMovementService::lockBalance, for the same reason: a create that
     * loses the race must find the winner's row rather than fail.
     */
    private function lockRow(int $itemId): ResinPoolBalance
    {
        $pool = ResinPoolBalance::query()
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if ($pool !== null) {
            return $pool;
        }

        return ResinPoolBalance::query()->firstOrCreate(
            ['item_id' => $itemId],
            ['quantity_kg' => '0.0000', 'avg_rate_per_kg' => '0.0000', 'unpriced_kg' => '0.0000'],
        );
    }

    /**
     * The moving average of two priced quantities. Extracted so fold() and
     * restore() cannot drift apart — they are the same arithmetic pointed in
     * opposite directions.
     */
    private function blend(string $heldQuantity, string $heldRate, string $addedQuantity, string $addedRate, string $newQuantity): string
    {
        if (bccomp($newQuantity, '0', self::SCALE) !== 1) {
            return '0.0000';
        }

        return bcdiv(
            bcadd(
                bcmul($heldQuantity, $heldRate, self::SCALE),
                bcmul($addedQuantity, $addedRate, self::SCALE),
                self::SCALE,
            ),
            $newQuantity,
            self::SCALE,
        );
    }

    /** Zero is the ABSENCE of a price, never a price of zero. */
    private function isPrice(mixed $rate): bool
    {
        return $rate !== null
            && is_numeric($rate)
            && bccomp((string) $rate, '0', self::SCALE) === 1;
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }
}
