<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\BagOverloadException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use Illuminate\Validation\ValidationException;

/**
 * ONE SCANNER, NOT TWO.
 *
 * A barcode → bag → lot → kilograms resolution, with the refusals that go
 * with it, existed in exactly one place: FactoryDayBinService::loadBag. The
 * store-issue handover (Phase 7.5, WS-B) needs precisely the same reading
 * of a barcode, and a second implementation of it would be the kind of
 * near-duplicate where the two copies silently disagree about which bags
 * quality has released.
 *
 * So the resolution moved HERE, unchanged, and both paths call it. The
 * refusals and their exact wording are the day bin's, preserved to the
 * character — FactoryDayBinBagLoadTest and DayBinAttackRefusalsTest pin
 * them, and they were right before this phase and stay right after it:
 *
 *   - an unknown barcode is not a bag;
 *   - a consumed or empty bag has nothing left to give;
 *   - a bag waiting for incoming QC is not production's yet, and a rejected
 *     one never will be (the arrival hold, owner-confirmed);
 *   - a bag with no store recorded against it cannot be moved out of one;
 *   - and no scan may pour off more than the bag physically holds.
 *
 * It resolves and refuses; it moves NOTHING. Deciding what a scan means —
 * a pour record at the common input, or a handover into Production/WIP — is
 * the caller's, and the two callers mean different things by it.
 *
 * It lives in Inventory because a bag and a lot are Inventory's own models,
 * which is also why it can be shared without either module reaching into
 * the other's tables.
 */
class MaterialBagIssueResolver
{
    /**
     * @param  ?string  $quantityKg  null means "the whole of what is left".
     * @return array{bag: MaterialBag, lot: MaterialLot, quantity: string, remaining: string, full: bool}
     *
     * @throws ValidationException|BagOverloadException
     */
    public function resolve(string $barcode, ?string $quantityKg): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw ValidationException::withMessages([
                'barcode' => 'Scan or type a bag barcode.',
            ]);
        }

        // Locked for the caller's transaction: two scanners on one bag must
        // not both read the same remaining kilograms.
        $bag = MaterialBag::query()->where('barcode', $barcode)->lockForUpdate()->first();
        if ($bag === null) {
            throw ValidationException::withMessages([
                'barcode' => 'Unknown bag barcode — no registered bag carries this code.',
            ]);
        }

        if ($bag->status === MaterialBagStatus::Consumed || bccomp((string) $bag->remaining_kg, '0', 4) !== 1) {
            throw ValidationException::withMessages([
                'barcode' => "Bag {$bag->barcode} is already consumed — nothing is left in it to load.",
            ]);
        }

        // ARRIVAL HOLD (owner-confirmed): a bag keeps its permanent identity
        // from the moment the lorry is unloaded, but it is not production's
        // until Incoming QC says so.
        if ($bag->status === MaterialBagStatus::WaitingQc) {
            throw ValidationException::withMessages([
                'barcode' => "Bag {$bag->barcode} is waiting for incoming QC — it cannot be loaded until quality releases it.",
            ]);
        }

        if ($bag->status === MaterialBagStatus::RejectedQc) {
            throw ValidationException::withMessages([
                'barcode' => "Bag {$bag->barcode} was rejected by incoming QC — it is out of usable stock and cannot be loaded.",
            ]);
        }

        if ($bag->current_warehouse_id === null) {
            throw ValidationException::withMessages([
                'barcode' => "Bag {$bag->barcode} has no store recorded against it — register the lot with its warehouse first.",
            ]);
        }

        $remaining = bcadd((string) $bag->remaining_kg, '0', 4);
        $quantity = $quantityKg !== null ? bcadd($quantityKg, '0', 4) : $remaining;

        if (bccomp($quantity, '0', 4) !== 1 || bccomp($quantity, $remaining, 4) === 1) {
            throw BagOverloadException::make($bag->barcode, $quantity, $remaining);
        }

        // The lot (and therefore the material) is resolved BEFORE any
        // mutation, because callers gate on which material this is and a 422
        // must never leave a half-applied scan behind it.
        $lot = $bag->lot()->first();

        return [
            'bag' => $bag,
            'lot' => $lot,
            'quantity' => $quantity,
            'remaining' => $remaining,
            'full' => bccomp($quantity, $remaining, 4) === 0,
        ];
    }

    /**
     * Pour the resolved quantity out of the bag. A full pour leaves the bag
     * Consumed (it holds nothing any more); a partial pour leaves it InStore
     * holding its remainder, because that is where it still physically is.
     *
     * NO MACHINE IS STAMPED ON THE BAG (FC-01, DEC-20260807-006):
     * day_bin_work_center_id keeps whatever history it already holds and is
     * written by neither caller.
     */
    public function pour(MaterialBag $bag, string $quantity, string $remaining, bool $full): MaterialBag
    {
        $bag->remaining_kg = bcsub($remaining, $quantity, 4);
        if ($full) {
            $bag->status = MaterialBagStatus::Consumed;
        }
        $bag->save();

        return $bag;
    }
}
