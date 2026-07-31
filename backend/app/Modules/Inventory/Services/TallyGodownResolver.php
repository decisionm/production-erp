<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Warehouse;

/**
 * THE one place that answers "which godown name does Tally see for this
 * warehouse?" — used by the voucher payload builders, the voucher preview
 * AND the production-readiness gate, so the three can never disagree about
 * whether a warehouse is postable.
 *
 * Why aliasing exists at all: this factory's Tally has EXACTLY ONE godown
 * (the company godown — every voucher line the accountant has ever booked
 * uses it). The ERP still wants real second locations locally — the factory
 * day bin is a genuine warehouse here so scan-loads and bin-vs-store
 * balances work — but those internal locations must stay invisible to the
 * accountant's books. So a voucher line whose warehouse is an internal bin
 * posts under the godown name Tally actually knows:
 *
 *   1. the warehouse itself, when it has a tally_guid (a real Tally godown);
 *   2. else the nearest ANCESTOR with a tally_guid (the day bin is created
 *      as a child of the company godown — its lines post under the parent);
 *   3. else, when the system has EXACTLY ONE Tally-linked warehouse, that
 *      one (this factory's reality: one godown, so there is nothing to
 *      choose between — an unparented internal bin can only mean it);
 *   4. else null — a multi-godown system with an unparented, unlinked
 *      warehouse is genuinely ambiguous, so nothing is guessed and the
 *      preview/readiness gate flags it exactly as before.
 */
class TallyGodownResolver
{
    /** Bounded parent walk — a cyclic hierarchy must not hang a voucher build. */
    private const MAX_PARENT_DEPTH = 32;

    /**
     * Memoized "the only Tally-linked warehouse, if there is exactly one".
     * Safe within one instance's lifetime (a request / one payload build);
     * warehouses don't change mid-voucher.
     */
    private ?Warehouse $soleLinked = null;

    private bool $soleLinkedLookedUp = false;

    /**
     * The Tally-known warehouse this one posts under, or null when no godown
     * Tally knows can stand in for it (rule 4 above).
     */
    public function resolve(?Warehouse $warehouse): ?Warehouse
    {
        if ($warehouse === null) {
            return null;
        }

        if ($warehouse->tally_guid !== null) {
            return $warehouse;
        }

        $current = $warehouse;
        for ($depth = 0; $depth < self::MAX_PARENT_DEPTH && $current->parent_id !== null; $depth++) {
            $parent = $current->parent;
            if ($parent === null) {
                break; // dangling/soft-deleted parent — fall through to rule 3
            }
            if ($parent->tally_guid !== null) {
                return $parent;
            }
            $current = $parent;
        }

        return $this->soleLinkedWarehouse();
    }

    /**
     * The godown NAME a voucher line should carry for this warehouse.
     * Falls back to the warehouse's OWN name when nothing resolves — the
     * exact pre-aliasing behaviour, so the preview still flags it by name
     * instead of the line silently losing its godown.
     */
    public function resolveName(?Warehouse $warehouse): ?string
    {
        if ($warehouse === null) {
            return null;
        }

        return $this->resolve($warehouse)?->name ?? $warehouse->name;
    }

    private function soleLinkedWarehouse(): ?Warehouse
    {
        if (! $this->soleLinkedLookedUp) {
            // limit(2): only "exactly one" matters, never the full list.
            $linked = Warehouse::query()->whereNotNull('tally_guid')->limit(2)->get();
            $this->soleLinked = $linked->count() === 1 ? $linked->first() : null;
            $this->soleLinkedLookedUp = true;
        }

        return $this->soleLinked;
    }
}
