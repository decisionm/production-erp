<?php

namespace App\Modules\TallySync\Models\Enums;

/**
 * What the importer could conclude about ONE Tally Sales voucher.
 *
 * Every state other than Matched is a REFUSAL TO GUESS, and each names a
 * different missing thing so a person can act on it. The importer never
 * creates a customer and never creates a sales order to make a match succeed
 * (DEC-20260831-012): an unmatched voucher is recorded as unmatched and waits.
 */
enum TallyInvoiceMatchState: string
{
    /** Exactly one live ERP sales order for this customer carries this PO reference. */
    case Matched = 'matched';

    /** Tally's voucher carries no purchase-order reference at all — nothing to match ON. */
    case UnmatchedNoReference = 'unmatched_no_reference';

    /** No ERP customer is linked to this Tally party ledger. */
    case UnmatchedNoCustomer = 'unmatched_no_customer';

    /** The customer is known; no live order of theirs carries this reference. */
    case UnmatchedNoOrder = 'unmatched_no_order';

    /** More than one order matched. Refused rather than picking one. */
    case Ambiguous = 'ambiguous';

    public function isMatched(): bool
    {
        return $this === self::Matched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched to a sales order',
            self::UnmatchedNoReference => 'Tally voucher carries no order reference',
            self::UnmatchedNoCustomer => 'No ERP customer for this Tally ledger',
            self::UnmatchedNoOrder => 'No sales order with this reference',
            self::Ambiguous => 'More than one sales order matched',
        };
    }
}
