<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use RuntimeException;

/**
 * MATERIAL STILL WAITING FOR INCOMING QC CANNOT LEAVE THE STORE — refused
 * at the balance, wherever the request came from.
 *
 * A sibling of InsufficientStockException and written to the same standard
 * (owner's screenshot, 30-Jul): names rather than ids, both quantities, and
 * a way out that the person reading it can actually take. The way out here
 * is never "enter more stock" — the material is physically there. It is
 * "quality releases the arrival", and nobody but quality can do it.
 *
 * `errorCode()` gives the SPA a stable word to branch on ('incoming_qc_hold')
 * so a screen can offer that route without parsing the sentence.
 */
class IncomingQcHoldException extends RuntimeException implements DomainException
{
    public static function forItem(
        int $itemId,
        int $warehouseId,
        string $available,
        string $held,
        int $bagCount,
        string $requested,
    ): self {
        return new self(sprintf(
            'Only %s of %s can leave %s right now: %s of what the balance shows is in %d bag(s) still waiting '
            .'for incoming QC, and material on hold is not production\'s yet (%s was asked for). Quality has to '
            .'release the arrival first — released bags can also be handed over by scanning them.',
            self::plain($available),
            self::label(Item::withTrashed()->find($itemId)?->name, 'this material'),
            self::label(Warehouse::withTrashed()->find($warehouseId)?->name, 'this store'),
            self::plain($held),
            $bagCount,
            self::plain($requested),
        ));
    }

    public function errorCode(): string
    {
        return 'incoming_qc_hold';
    }

    /** Trailing zeros are the column's, not the sentence's. */
    private static function plain(string $number): string
    {
        return str_contains($number, '.')
            ? (rtrim(rtrim($number, '0'), '.') ?: '0')
            : $number;
    }

    /** A deleted or unnamed master must still read as a sentence, never as a hole. */
    private static function label(?string $name, string $fallback): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : $fallback;
    }
}
