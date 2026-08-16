<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sales_order_id', 'customer_id', 'status', 'invoice_date', 'due_date', 'notes', 'created_by'])]
class Invoice extends Model
{
    /**
     * Read-side decorations set by InvoiceService (via SalesDocumentTraceService)
     * and read by InvoiceResource — plain properties, never attributes:
     * not persisted, not in toArray(), null on a bare model.
     *
     *   tallyLink   the TallyLink for this invoice's Sales entry
     *               (TallySyncLinkService), or null when none exists — a
     *               draft has none. The service decorates every row it
     *               returns (list, show, create, issue).
     *   trace       the show endpoint's chain (sales_order, tally)
     */
    public ?array $tallyLink = null;

    /** @var array<string, mixed>|null */
    public ?array $trace = null;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "INV-{id}" — the same number the Sales voucher carries (TallySyncService::enqueueSalesInvoice). */
    public function documentNumber(): string
    {
        return "INV-{$this->id}";
    }
}
