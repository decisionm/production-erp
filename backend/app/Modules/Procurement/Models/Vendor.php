<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// tally_ledger_name (Phase 6): the vendor's ledger name in Tally — the party a
// Purchase Order voucher names. Nullable; NEVER populated by the ERP itself
// (no Tally read) — Accounts sets it on the vendor form. Supplier identity
// (FC-06), served to the readers who already see the vendor's own name.
#[Fillable(['code', 'name', 'email', 'phone', 'address', 'gstin', 'state_code', 'is_active', 'tally_ledger_name'])]
class Vendor extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
