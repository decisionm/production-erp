<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;

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
 *   3. else, when the system has EXACTLY ONE Tally-linked warehouse OF THE
 *      BOUND TALLY COMPANY, that one (this factory's reality: one godown, so
 *      there is nothing to choose between — an unparented internal bin can
 *      only mean it);
 *   4. else null — a multi-godown system with an unparented, unlinked
 *      warehouse is genuinely ambiguous, so nothing is guessed and the
 *      preview/readiness gate flags it exactly as before.
 */
class TallyGodownResolver
{
    public function __construct(private readonly AppSettingService $settings) {}

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

    /**
     * The godown NAME for a voucher that has NO warehouse of its own — an
     * ERP-raised Purchase Order names no receiving store until its GRN,
     * yet Tally's order allocations (BATCHALLOCATIONS / ORDERDUEDATE) sit
     * under a godown. Rule 3 above, applied without a warehouse: when the
     * system has EXACTLY ONE Tally-linked warehouse it is that one (this
     * factory's reality: one company godown); otherwise null — a
     * multi-godown system has nothing to choose by, so nothing is guessed
     * and the caller refuses to stage. Additive (Phase 6, WS-C); the
     * warehouse-bearing paths above are untouched.
     */
    public function soleTallyGodownName(): ?string
    {
        return $this->soleLinkedWarehouse()?->name;
    }

    /**
     * "Exactly one" — COUNTED AMONG THIS COMPANY'S GODOWNS, once the data can
     * say which those are.
     *
     * The original rule counted every Tally-linked warehouse, and was right
     * while the table only ever held one company's godowns. It stopped being
     * right: the rehearsal database holds seven, carrying TWO different Tally
     * company ids — six left behind by another company, one from the company
     * this instance is bound to. Seven is not one, so nothing resolved and
     * every purchase order refused with `godown_unresolved`.
     *
     * THE COMPANY IS A TIE-BREAKER, NOT A PRECONDITION, and that distinction
     * is the whole design. Scoping unconditionally looked correct and was
     * not: no godown anywhere records a company yet, because the pull only
     * ever wrote it on create. Requiring one would have resolved NOTHING on a
     * live instance until a fresh masters pull ran — and `resolveName()` falls
     * back to the warehouse's own name, so a work-in-progress consumption line
     * would have quietly started naming "Work In Progress", a godown Tally
     * does not have. The suite caught exactly that.
     *
     * So: narrow by company only when the narrowing can be done — a company is
     * bound AND at least one linked warehouse records THAT company. A table
     * whose rows all name some OTHER company cannot be narrowed either, and is
     * likewise counted whole; that is a compatibility fallback rather than a
     * guarantee, and it is the reason this is a tie-breaker and not a gate. Every previously-resolving system keeps resolving to the same
     * godown; the two-company case starts resolving once a pull records who
     * owns which. Rule 4 is untouched: two godowns of the same company are
     * still genuinely ambiguous, and still null.
     *
     * KEY_COMPANY is read through Core's AppSettingService. The constant is
     * TallySync's, named rather than copied so the key has one spelling; the
     * masters endpoint that BINDS the company is its only writer.
     */
    private function soleLinkedWarehouse(): ?Warehouse
    {
        if ($this->soleLinkedLookedUp) {
            return $this->soleLinked;
        }

        $this->soleLinkedLookedUp = true;

        // The full list, not limit(2): the company narrowing below has to see
        // every candidate before it can count what is left.
        $linked = Warehouse::query()->whereNotNull('tally_guid')->get();

        $bound = $this->settings->get(TallySettingsController::KEY_COMPANY);
        $bound = is_string($bound) && trim($bound) !== '' ? trim($bound) : null;

        $ofBoundCompany = $linked->filter(
            fn (Warehouse $warehouse): bool => $warehouse->tally_company !== null
                && $bound !== null
                && trim((string) $warehouse->tally_company) === $bound,
        );

        // Narrow only where the data supports narrowing. A table whose godowns
        // record no company at all cannot be narrowed, and is counted whole
        // exactly as it was before.
        $candidates = $ofBoundCompany->isNotEmpty() ? $ofBoundCompany : $linked;

        $this->soleLinked = $candidates->count() === 1 ? $candidates->first() : null;

        return $this->soleLinked;
    }
}
