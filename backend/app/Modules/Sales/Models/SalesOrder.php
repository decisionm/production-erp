<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['customer_id', 'status', 'order_date', 'expected_date', 'notes', 'created_by'])]
class SalesOrder extends Model
{
    /**
     * The statuses an order is still OPEN in — the only ones an unmet
     * promise date can be late for. A completed or cancelled order is never
     * overdue: its date is history, not a promise still standing.
     */
    public const OPEN_STATUSES = [
        SalesOrderStatus::Draft,
        SalesOrderStatus::Confirmed,
        SalesOrderStatus::PartiallyDelivered,
    ];

    /** The statuses whose expected date and notes may still be changed. */
    public const EDITABLE_STATUSES = [
        SalesOrderStatus::Draft,
        SalesOrderStatus::Confirmed,
    ];

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

    /**
     * THE EDIT RULE, in one place — SalesOrderService::update() enforces it
     * and SalesOrderResource::can_edit reports it, so the drawer's button
     * and the server's refusal can never disagree. The expected date and
     * the notes belong to the desk while the order is still draft or
     * confirmed; from the first dispatch onwards they are history.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * Is this order's promise date already past, on the FACTORY's calendar?
     *
     * Derived on every read and persisted nowhere — an order becomes
     * overdue by the clock moving, not by anything being written to it.
     *
     * The app clock is UTC and the factory's day is IST (CLAUDE.md: never
     * compare now() against a wall-clock value without localising it
     * first). Judged today-against-today as ISO date strings, which sort
     * lexicographically — so at 19:00 UTC, when the factory is already on
     * the next morning, yesterday's promise reads overdue here too.
     */
    public function isOverdue(): bool
    {
        if ($this->expected_date === null) {
            return false;
        }

        if (! in_array($this->status, self::OPEN_STATUSES, true)) {
            return false;
        }

        return $this->expected_date->toDateString() < self::factoryToday();
    }

    /** Today on the factory's wall clock, as an ISO date — the one "today" every overdue read uses. */
    public static function factoryToday(): string
    {
        return now()->setTimezone(config('tally-sync.factory_timezone'))->toDateString();
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
