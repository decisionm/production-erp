<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Config, not code: maps a voucher posting role (sales, cgst, resin_consumption,
 * …) to the exact Tally ledger name this client uses. The voucher builders read
 * these instead of hardcoding ledger names, so onboarding a differently-set-up
 * client is a data change, never a code change.
 */
#[Fillable(['role', 'tally_ledger_name'])]
class TallyLedgerMapping extends Model
{
    //
}
