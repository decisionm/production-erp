<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\TallySync\Models\Enums\TallyInvoiceMatchState;
use App\Modules\TallySync\Models\TallySalesInvoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

// `customer_po_reference` is the CUSTOMER's own purchase-order number — the
// string that matches this order to their paperwork and to a Tally invoice.
// Fillable because SalesOrderService::create() mass-assigns an explicit array;
// it is recorded and displayed only, and emits no voucher in this build.
#[Fillable(['customer_id', 'status', 'order_date', 'expected_date', 'customer_po_reference', 'notes', 'created_by'])]
class SalesOrder extends Model
{
    /**
     * The show endpoint's trace (deliveries with cartons and Tally links,
     * invoices with Tally links), set by SalesOrderService::show() and read
     * by SalesOrderResource. A plain property, not an attribute: it is never
     * persisted, never serialised by toArray(), and absent (null) on every
     * list row.
     *
     * @var array{deliveries: list<array<string, mixed>>, invoices: list<array<string, mixed>>}|null
     */
    public ?array $trace = null;

    /**
     * Whether every line of this LIVE order is covered by what has already
     * been delivered plus what is still held for it — the list row's "Ready
     * for dispatch" badge, computed by Inventory's FulfilmentQueueService.
     *
     * A plain property like `trace` above: never persisted, never serialised
     * by toArray(). It is a STAMPING SEAM, and it is null today because
     * nothing stamps it — SalesOrderResource falls back to asking the service
     * per order. It exists so a future bulk read can answer a whole page in
     * one query without touching the resource, which is the shape
     * DeliveryService::paginate already uses for its Tally links and carton
     * counts ("two queries for the page, never per row").
     */
    public ?bool $ready_for_dispatch = null;

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Every invoice line raised against this order, across all its invoices — the invoiced-quantity read. */
    public function invoiceLines(): HasManyThrough
    {
        return $this->hasManyThrough(InvoiceLine::class, Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The Tally Sales vouchers IMPORTED against this order.
     *
     * The ERP raises no Tally invoice — Tally creates it, along with the
     * e-invoice and e-way details, and the ERP imports and matches it
     * (DEC-20260831-012). So this is a record of what the other book says, and
     * it is a hasMany rather than a hasOne on purpose: a single order can be
     * invoiced across several Tally vouchers, and the arithmetic of whether
     * that adds up to the order is not something this relation asserts.
     */
    public function tallyInvoices(): HasMany
    {
        return $this->hasMany(TallySalesInvoice::class);
    }

    /**
     * Whether Tally has invoiced this order — DERIVED, never stored.
     *
     * Deliberately not a column. A denormalised flag would drift the moment a
     * voucher were re-imported, re-matched or matched late, and the honest
     * answer is simply whether a matched Tally voucher exists right now.
     *
     * It does not touch `status`. The order's own lifecycle
     * (draft/confirmed/partially_delivered/completed/cancelled) is driven by
     * what the FACTORY did — delivery — and Tally invoicing is a separate
     * book's event. Whether a Tally invoice should also CLOSE an order is a
     * business rule nobody has stated, so it is not invented here.
     */
    public function isInvoicedInTally(): bool
    {
        return $this->tallyInvoices()
            ->where('match_state', TallyInvoiceMatchState::Matched)
            ->exists();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "SO-{id}" — the number staff quote for this order. */
    public function documentNumber(): string
    {
        return "SO-{$this->id}";
    }

    // ---- totals -------------------------------------------------------------

    /** Sum of every line's ordered quantity, exact to 4dp. */
    public function orderedQuantity(): string
    {
        return $this->sumLines('quantity');
    }

    /** Sum of every line's delivered quantity, exact to 4dp. */
    public function deliveredQuantity(): string
    {
        return $this->sumLines('quantity_delivered');
    }

    /**
     * Sum of every invoice line's quantity across this order's invoices
     * (draft included — a draft is still a document raised against the
     * order). Reads the `invoiced_quantity` aggregate when the service
     * loaded it (withSum / loadSum), else asks the relation.
     */
    public function invoicedQuantity(): string
    {
        // withSum() yields SQL NULL — not 0 — for an order with no invoice
        // lines, which on this factory is most of them (real sales are
        // invoiced in Tally). Judged on the KEY being present, not on the
        // value: `?? null` read that NULL as "not loaded" and re-ran the SUM
        // once per row on the list — twenty extra queries a page.
        $attributes = $this->getAttributes();
        $sum = array_key_exists('invoiced_quantity', $attributes)
            ? ($attributes['invoiced_quantity'] ?? '0')
            : $this->invoiceLines()->sum('quantity');

        return bcadd((string) ($sum ?? '0'), '0', 4);
    }

    /** How many deliveries stand against this order — the loaded count when present. */
    public function deliveriesCount(): int
    {
        return (int) ($this->getAttributes()['deliveries_count'] ?? $this->deliveries()->count());
    }

    /** How many invoices stand against this order — the loaded count when present. */
    public function invoicesCount(): int
    {
        return (int) ($this->getAttributes()['invoices_count'] ?? $this->invoices()->count());
    }

    /**
     * THE CANCEL RULE, in one place — SalesOrderService::cancel() enforces
     * it and SalesOrderResource::can_cancel reports it, so the button and
     * the refusal can never disagree. Cancellable only while nothing has
     * left and nothing has been billed: draft or confirmed, every line's
     * quantity_delivered zero, and no invoice (draft included) against it.
     */
    public function isCancellable(): bool
    {
        if (! in_array($this->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
            return false;
        }

        if (bccomp($this->deliveredQuantity(), '0', 4) !== 0) {
            return false;
        }

        return $this->invoicesCount() === 0;
    }

    private function sumLines(string $column): string
    {
        $this->loadMissing('lines');

        return $this->lines->reduce(
            fn (string $carry, SalesOrderLine $line) => bcadd($carry, (string) $line->{$column}, 4),
            '0.0000',
        );
    }
}
