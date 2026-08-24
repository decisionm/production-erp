<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;

/**
 * HOW MANY KILOGRAMS OF A MATERIAL A STORE IS HOLDING FOR INCOMING QC —
 * one reading, shared by every door that could let them out.
 *
 * A bag born on a goods receipt is `waiting_qc` and is not production's
 * until Incoming Inspection releases it (the owner-confirmed arrival hold
 * MaterialBagIssueResolver refuses at the scanner). But the hold lives on
 * the BAG and the outflow doors work on the BALANCE, and the balance counts
 * held kilograms — so every door that decrements a balance has to subtract
 * the hold itself. That subtraction is this class, in one place, because two
 * copies of it would eventually disagree about which bags quality released.
 *
 * WHAT COUNTS AS HELD, and what deliberately does not:
 *  · `waiting_qc` bags in this store — held. Their kilograms are on the
 *    balance and must not leave it.
 *  · `rejected_qc` bags — NOT held. The inspection already took their
 *    kilograms off the balance through its Rejections Out issue, and
 *    withholding them twice would refuse material that is genuinely there.
 *  · the boundary bag an inspection leaves `waiting_qc` (the open bag-split
 *    question) — held, by falling out of the first rule. That is the
 *    conservative reading, and the one already written into the
 *    inspection's own note.
 *  · a `waiting_qc` bag with no store recorded against it — held against
 *    EVERY store, because nothing says which one it is in. No code path
 *    creates one (TraceabilityService sets `waiting_qc` only with a GRN,
 *    and a GRN line always carries a warehouse), so this is fail-closed
 *    cover for a backfill rather than a live case; the scanner refuses such
 *    a bag outright for the same reason.
 *  · an item with no bags at all — nothing held, and every door behaves
 *    exactly as it did before. No MeasurementType is consulted anywhere
 *    here: whether counted packaging should carry an arrival hold of its
 *    own is an OPEN OWNER QUESTION, and reading bag existence rather than a
 *    unit keeps this out of that decision.
 *
 * `remaining_kg`, never `original_kg` — a part-poured bag holds only what is
 * still in it. Summed with bcadd over rows already fetched, never a SQL
 * SUM(), which comes back through PHP as a float: a float has no business
 * anywhere near a quantity that decides whether material may move.
 *
 * LOCK ORDER IS BAGS, THEN BALANCE — everywhere, without exception. This
 * class takes the bag locks; the caller takes the balance lock after it.
 * IncomingInspectionService::dispositionBags locks its bags first too, and
 * reverses nothing. Taken the other way round anywhere, the two would
 * deadlock. (`lockForUpdate` is a no-op on SQLite, so the suite pins the
 * arithmetic and the ORDER of the reads, not a real serialisation.)
 */
class IncomingQcHold
{
    /**
     * The held kilograms and the number of bags holding them, with those
     * bags locked for the caller's transaction so an inspection releasing
     * them cannot interleave with the decrement about to be checked.
     *
     * @return array{0: string, 1: int} [held kg, bag count]
     */
    public function lockAndSum(int $itemId, int $warehouseId): array
    {
        $bags = MaterialBag::query()
            ->whereIn('material_lot_id', MaterialLot::query()->select('id')->where('item_id', $itemId))
            ->where('status', MaterialBagStatus::WaitingQc->value)
            ->where(fn ($query) => $query
                ->where('current_warehouse_id', $warehouseId)
                ->orWhereNull('current_warehouse_id'))
            ->lockForUpdate()
            ->get();

        $held = '0.0000';
        foreach ($bags as $bag) {
            $held = bcadd($held, (string) $bag->remaining_kg, 4);
        }

        return [$held, $bags->count()];
    }

    /**
     * What may leave, given a balance and a hold: never below zero. A hold
     * larger than the balance means kilograms already left that should not
     * have (or a backfilled bag), and the honest answer to "how much may go"
     * is then none — blunt, and fail-closed on purpose.
     */
    public function available(string $balance, string $held): string
    {
        $available = bcsub($balance, $held, 4);

        return bccomp($available, '0', 4) === -1 ? '0.0000' : $available;
    }
}
