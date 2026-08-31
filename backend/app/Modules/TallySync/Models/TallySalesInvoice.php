<?php

namespace App\Modules\TallySync\Models;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyInvoiceMatchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Sales voucher READ OUT OF TALLY. Never one the ERP wrote.
 *
 * No #[Fillable]: TallySalesInvoiceImporter is the only writer, and it sets
 * every column explicitly. Nothing about this row may be mass-assigned from a
 * request — it is a record of what another system says, not ERP-owned data.
 */
class TallySalesInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'imported_at' => 'datetime',
            'amount' => 'decimal:4',
            'match_state' => TallyInvoiceMatchState::class,
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
