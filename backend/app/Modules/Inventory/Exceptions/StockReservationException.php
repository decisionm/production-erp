<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\StockReservation;
use RuntimeException;

/**
 * A stock hold was asked for something the facts do not allow. Always an
 * expected business-rule refusal (a 422 naming the actual figures), never a
 * bug — the storekeeper is looking at a screen that was true a moment ago
 * and the numbers moved underneath it.
 *
 * EVERY MESSAGE QUOTES THE NUMBER THAT REFUSED, because the store's next
 * action depends on which wall they hit: "no free stock" means send it to
 * production, "already fully ordered" means the line does not need it, and
 * "the order is not confirmed" means go back to the sales desk.
 *
 * FC-06: no message here names a rate, a cost or a supplier.
 */
class StockReservationException extends RuntimeException implements DomainException
{
    /** The order has to be live before its lines may hold anything. */
    public static function orderNotOpen(string $documentNumber, string $status): self
    {
        return new self(
            "Sales order {$documentNumber} is {$status}: stock can only be held for a confirmed or "
            .'partially delivered order.'
        );
    }

    /**
     * NO BALANCE ROW AT ALL is the same answer as a zero balance — the
     * factory holds none of this item in that warehouse. Said in one
     * message so the store never has to tell an absent row from an empty
     * one.
     */
    public static function nothingFree(string $itemName, string $warehouseName, string $free): self
    {
        return new self(
            "There is no free {$itemName} in {$warehouseName} to hold — {$free} is available after existing "
            .'holds. Send the shortfall to production instead.'
        );
    }

    /** The classic race: the screen showed free stock, somebody else took it first. */
    public static function notEnoughFree(string $itemName, string $free, string $requested): self
    {
        return new self(
            "Only {$free} {$itemName} is free after existing holds — {$requested} was asked for. "
            .'Somebody may have held it since this screen was loaded; reload and try the smaller figure.'
        );
    }

    /**
     * S5, THE DEMAND CAP. A line may never hold more than it still owes the
     * customer: ordered less delivered less what it already holds. Without
     * it a line for 100 could sit on 500 pieces of free stock and starve
     * every other order for something nobody will ever ship.
     */
    public static function exceedsRemainingDemand(string $lineLabel, string $remaining, string $requested): self
    {
        return new self(
            "Order line {$lineLabel} still needs {$remaining} — holding {$requested} would reserve more than "
            .'the customer ordered. Reduce the quantity, or re-point the surplus to another line.'
        );
    }

    /** A quantity has to be a quantity. */
    public static function quantityNotPositive(string $requested): self
    {
        return new self("A hold has to be for more than nothing — {$requested} was asked for.");
    }

    /**
     * A SPENT HOLD CANNOT BE GIVEN UP. The stock left the building against
     * it; releasing it would claim the delivery never happened. A
     * PARTIALLY consumed hold still releases its remainder — that is the
     * ordinary end of a delivered line.
     */
    public static function cannotRelease(StockReservation $reservation): self
    {
        return new self(
            "Hold #{$reservation->id} is {$reservation->status->value}: it is already spent and cannot be "
            .'released. Stock that left on a delivery is corrected by a delivery, never by giving up the hold.'
        );
    }

    /** Re-pointing moves a hold between lines of live orders — not onto itself. */
    public static function cannotRepointToSameLine(StockReservation $reservation): self
    {
        return new self(
            "Hold #{$reservation->id} is already against that order line — there is nothing to re-point."
        );
    }

    /** A hold can only be re-pointed to a line asking for the SAME item. */
    public static function repointItemMismatch(string $held, string $target): self
    {
        return new self(
            "This hold is on {$held}; the line it would move to asks for {$target}. A hold can only be "
            .'re-pointed to a line for the same item.'
        );
    }

    /** More than the hold is still holding cannot be moved out of it. */
    public static function repointExceedsHold(string $outstanding, string $requested): self
    {
        return new self(
            "This hold is only still holding {$outstanding} — {$requested} cannot be re-pointed out of it."
        );
    }

    public function errorCode(): string
    {
        return 'stock_reservation';
    }
}
