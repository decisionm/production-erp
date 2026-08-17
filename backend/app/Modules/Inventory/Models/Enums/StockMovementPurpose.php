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
