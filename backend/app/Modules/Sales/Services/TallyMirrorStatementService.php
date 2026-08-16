<?php

namespace App\Modules\Sales\Services;

/**
 * THE HONESTY STATEMENT the Sales pages render (Phase 3.5, GET
 * /sales/tally-mirror): what these pages are NOT.
 *
 * DEC-20260809-003 (owner, 09-Aug-2026): "ALL real sales are invoiced
 * directly in Tally — the ERP Sales module is demo-scale". The ERP is not
 * the sales system of record, and it has NO read path from Tally today
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
    public const DECISION = 'DEC-20260809-003';

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
            'headline' => 'Real sales are invoiced in Tally',
            'body' => 'Tally-side Sales and Sales Order vouchers are not mirrored into this ERP. '
                .'The documents on these pages are the ERP-originated subset only. '
                .'Reads from Tally are deliberate and human-triggered; none is scheduled.',
            'erp_invoice_builder' => [
                'validated' => false,
                'note' => 'The ERP\'s Sales voucher XML is not yet validated against real Tally and carries no GST — '
                    .'do not post real invoices from here while DEC-20260809-003 stands.',
            ],
            'payments_recorded_here' => false,
            'payments_note' => 'An invoice is never marked paid by this ERP — receipts live in Tally.',
        ];
    }
}
