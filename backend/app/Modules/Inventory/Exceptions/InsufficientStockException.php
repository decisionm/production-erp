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
     */
    public static function forItem(int $itemId, int $warehouseId, string $available, string $requested): self
    {
        return new self(sprintf(
            'Not enough %s (item #%d) in %s (warehouse #%d): %s recorded there, %s needed. '
            .'Receive the material against its purchase, or enter its opening stock on the Day Bin page, then try again.',
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
