<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE OUTSTANDING BILL, as the factory's Tally already holds it.
 *
 * Written only by TallyReceivableSyncService from a Bills Receivable export.
 * Nothing in the ERP edits one and no voucher is ever posted from them: they
 * exist so a person chasing a client can see what is owed and how long it has
 * been owed, without reading it off a second screen.
 *
 * THE SIGN IS TALLY'S. A credit note or an advance arrives negative and stays
 * negative. Taking an absolute value here — as the purchase-rate reader does,
 * correctly, for an inventory amount whose sign is a double-entry artefact —
 * would turn a client who is in CREDIT into one of the factory's largest
 * debtors. The sign is information here, not convention.
 */
#[Fillable([
    'party_ledger_name', 'party_ledger_guid',
    'bill_reference', 'bill_date', 'due_date',
    'closing_amount', 'opening_amount',
    'as_of', 'tally_company', 'tally_synced_at',
])]
class TallyReceivableBill extends Model
{
    /**
     * Money as DECIMAL STRINGS and dates as dates — the suite's sqlite leg and
     * the live MySQL hand a `decimal` column back differently (a PHP number
     * against a string), and an amount whose precision depends on where it was
     * read is an amount somebody will chase a client for. TallyPurchaseRate
     * fixes one answer for both the same way.
     */
    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'as_of' => 'date',
            'tally_synced_at' => 'datetime',
            'closing_amount' => 'decimal:4',
            'opening_amount' => 'decimal:4',
        ];
    }
}
