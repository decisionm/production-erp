<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * WHY a stock movement happened — beside StockMovementType, which only says
 * which way the quantity went. A receipt is a receipt whether it is a
 * purchase arriving, a batch's finished goods, an opening balance copied
 * from Tally or a correction; until now the only way to tell was to parse
 * the free-text reference. This is that fact written down once, by the
 * writer that knows it, so a report can group the ledger by intent without
 * pattern-matching strings.
 *
 * Unknown is a real value, not an absence: it is what every movement gets
 * whose writer has not (yet) said why — every pre-existing caller of
 * StockMovementService, every manual receipt/issue/transfer form, and every
 * historical row the backfill could not classify unambiguously. It is
 * deliberately never null and never guessed.
 */
enum StockMovementPurpose: string
{
    /** An opening balance — the ledger's starting position (e.g. copied from Tally's stock summary). */
    case Opening = 'opening';

    /** Material received from outside — a purchase arriving on a GRN. */
    case Receipt = 'receipt';

    /**
     * The store handed material to production — the transfer pair that moves
     * it out of the store and into Production/WIP (Phase 7.5, WS-B).
     *
     * IT IS NOT A CONSUMPTION, which is the whole reason it exists: the
     * material has left the store's shelf and is standing with production,
     * unconsumed. DEC-20260817-001 names Production/WIP as the location that
     * holds exactly that state, so this purpose always rides a TRANSFER pair
     * rather than an issue — a purpose alone would be invisible to
     * `inventory:check-ledger`, which signs by movement TYPE, and would
     * create no second state at all.
     */
    case IssueToProduction = 'issue_to_production';

    /**
     * Material production did not use, coming back from Production/WIP to
     * the store it came out of. The mirror of IssueToProduction, and never a
     * receipt: nothing new arrived in the factory.
     */
    case ReturnFromProduction = 'return_from_production';

    /** Material consumed by production — a batch's consumption lines. */
    case Consumption = 'consumption';

    /** Finished goods produced — a batch's output receipt. */
    case Output = 'output';

    /** Finished goods dispatched to a customer — a delivery. */
    case Dispatch = 'dispatch';

    /** A deliberate correction that is neither of the above (a count, a write-off, a manual fix). */
    case Adjustment = 'adjustment';

    /** The ERP matched to Tally's closing position — a reconcile run. */
    case Reconcile = 'reconcile';

    /** The writer did not say. The default for every caller that predates the column. */
    case Unknown = 'unknown';
}
