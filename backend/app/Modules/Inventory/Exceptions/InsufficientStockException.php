<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use RuntimeException;

class InsufficientStockException extends RuntimeException implements DomainException
{
    /**
     * NAMES, NOT NUMBERS — and a way out.
     *
     * What a supervisor actually saw on the floor (owner's screenshot,
     * 30-Jul) was:
     *
     *   "Could not complete batch — Insufficient stock for item #592 at
     *    warehouse #10: available 0.0000, requested 118.998."
     *
     * Every word of that is true and none of it is usable. Nobody on the
     * floor knows item 592 by number or which store is #10, and the
     * sentence named no fix — so the one screen that could have helped just
     * said no, with the resin already through the machine.
     *
     * The completion path no longer raises this at all: it records the
     * shortfall and lets the balance go negative (see config/production.php
     * 'stock'). What is left here is every OTHER issue — work orders,
     * rework, subcontract, deliveries, maintenance — plus the completion
     * path itself on a deployment that has deliberately turned the block
     * back on. Each of those still needs to say what the material is, where
     * it was looked for, both quantities, and what to do about it. The ids
     * stay, in parentheses, for whoever is reading a log rather than a
     * screen.
     *
     * Names are resolved WITH TRASHED on purpose: a master cleanup that
     * soft-deleted the item or the warehouse is exactly the case in which
     * the numbers-only message helped least, so it must not be the case
     * that quietly degrades back to one.
     *
     * THE WAY OUT CHANGED IN PHASE 7.5, and only the way out. The sentence
     * used to end "enter its opening stock on the Day Bin page".
     * DEC-20260817-001 states the factory's logical inventory locations as
     * Raw Material Store -> Production/WIP -> Finished Goods Store and
     * records that there is no Day Bin — so the bin is not a place stock
     * enters, and a refusal that sent the storekeeper there sent him
     * somewhere that cannot answer him. (The Day Bin SCREEN still exists
     * and still serves the common-input bag scan; what it is not, any
     * more, is a stock location.) The two ways out named instead cover the
     * RAW-MATERIAL shortfalls this is raised on in practice — work orders,
     * rework, subcontract, maintenance and the completion path: material
     * is received in against its purchase, or it comes back from
     * production on the store issue that took it there. They are not
     * claimed to cover every case: a finished-good short at dispatch is
     * answered by the production that books it in as Output, which is
     * neither a purchase nor a return, and the sentence is worded as a
     * conditional ("if it has already gone to production") so it does not
     * assert otherwise. Neither way out names a quantity: this class
     * reports the gap, it never proposes a figure.
     */
    public static function forItem(int $itemId, int $warehouseId, string $available, string $requested): self
    {
        return new self(sprintf(
            'Not enough %s (item #%d) in %s (warehouse #%d): %s recorded there, %s needed. '
            .'Receive the material against its purchase so the store holds it, or — if it has already gone to '
            .'production — return the unused part on its store issue, then try again.',
            self::label(Item::withTrashed()->find($itemId)?->name, 'this material'),
            $itemId,
            self::label(Warehouse::withTrashed()->find($warehouseId)?->name, 'this store'),
            $warehouseId,
            $available,
            $requested,
        ));
    }

    /** A deleted or unnamed master must still read as a sentence, never as a hole. */
    private static function label(?string $name, string $fallback): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : $fallback;
    }
}
