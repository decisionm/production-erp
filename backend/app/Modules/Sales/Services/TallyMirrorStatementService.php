<?php

namespace App\Modules\Sales\Services;

/**
 * THE HONESTY STATEMENT the Sales pages render (Phase 3.5, GET
 * /sales/tally-mirror): what these pages are NOT.
 *
 * DEC-20260831-007 (owner, 31-Aug-2026) REVERSED THE DIRECTION THIS PAGE USED
 * TO STATE. It supersedes DEC-20260809-003 ("ALL real sales are invoiced
 * directly in Tally — the ERP Sales module is demo-scale"): the ERP now
 * ORIGINATES the sale and posts both Delivery Notes and Sales Invoices.
 *
 * WHAT DID NOT CHANGE, and is why this statement still exists: nothing flows
 * the OTHER way. The ERP still has NO read path from Tally today
 * (the agent stopped reading after the 08-Aug corruption; any deliberate
 * read is a separate, human-triggered, owner-sanctioned thing — open
 * question Q36 on timing). So the sales orders, deliveries and invoices on
 * these pages are the ERP-ORIGINATED subset only, and Tally's own Sales /
 * Sales Order vouchers are not mirrored here — the page must SAY so rather
 * than show an empty table as if it were the truth.
 *
 * The strings below are the contract: the frontend renders them verbatim
 * (never hardcodes its own), tests assert `mirrored === false` and
 * `decision`. Change them here and nowhere else — and only with the
 * decision that makes them true still standing.
 */
class TallyMirrorStatementService
{
    public const DECISION = 'DEC-20260831-007';

    /**
     * @return array{
     *     mirrored: bool,
     *     decision: string,
     *     headline: string,
     *     body: string,
     *     erp_invoice_builder: array{validated: bool, note: string},
     *     payments_recorded_here: bool,
     *     payments_note: string,
     * }
     */
    public function statement(): array
    {
        return [
            'mirrored' => false,
            'decision' => self::DECISION,
            'headline' => 'Sales raised here post to Tally; Tally is not read back',
            'body' => 'Tally-side Sales and Sales Order vouchers are not mirrored into this ERP. '
                .'The documents on these pages are the ERP-originated subset only, and a sale keyed straight into '
                .'Tally will not appear here. Reads from Tally are deliberate and human-triggered; none is scheduled.',
            'erp_invoice_builder' => [
                // VALIDATED, NOT YET LIVE-POSTED — two different things, and the
                // old statement said neither of them. It claimed the builder was
                // unvalidated and carried no GST; both became false when the
                // voucher was rebuilt against 55 real Sales vouchers from the
                // factory's own Tally. What is still true is that it has never
                // posted to a live Tally, and that a missing ledger, HSN, rate,
                // godown or company makes it refuse rather than guess.
                'validated' => true,
                'note' => 'The ERP\'s Sales voucher was checked field by field against 55 real Sales vouchers exported '
                    .'from this factory\'s Tally and emits CGST/SGST or IGST, Rounding Off and a per-line ledger. It has '
                    .'not yet been posted to a live Tally, and it refuses to stage at all when the customer ledger, HSN, '
                    .'rate, godown or allowed company is missing — a refusal never blocks the invoice.',
            ],
            'payments_recorded_here' => false,
            'payments_note' => 'An invoice is never marked paid by this ERP — receipts live in Tally.',
        ];
    }
}
