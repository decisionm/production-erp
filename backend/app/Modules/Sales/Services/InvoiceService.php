<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `InvoiceStatus::Paid` is deliberately NOT wired anywhere in this service:
 * receipts are recorded in Tally (DEC-20260809-003 — real sales are
 * invoiced there), so the ERP never marks an invoice paid. The status
 * stays in the enum for the rows that already carry it and for the reads
 * (unpaid()/issued()) that filter on it.
 */
class InvoiceService
{
    /** Loaded on every invoice the service hands back, so the resource never lazy-loads. */
    private const WITH = ['lines.item', 'customer', 'salesOrder'];

    public function __construct(
        private readonly SalesDocumentQuery $query,
        private readonly SalesDocumentTraceService $trace,
    ) {}

    /**
     * The list, filtered (Phase 3.5). $filters is the validated
     * ListInvoicesRequest input; an empty array is the unfiltered list —
     * newest first, same page size as before. Every row is stamped with its
     * Sales TallyLink (null while draft), one query for the page.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::query()->with(self::WITH);

        $this->applyFilters($query, $filters);
        $this->query->applySort($query, $filters['sort'] ?? null, ['invoice_date']);

        $page = $query->paginate($perPage);
        $this->trace->decorateInvoices($page->getCollection());

        return $page;
    }

    /**
     * One invoice with its chain — the show endpoint: the order it bills
     * (with customer) and its Sales link, as `trace`.
     */
    public function show(Invoice $invoice): Invoice
    {
        $this->decorate($invoice);
        $invoice->trace = $this->trace->invoiceTrace($invoice);

        return $invoice;
    }

    /**
     * Unpaid invoices (draft or issued) — the source Finance's receivables
     * report reads from. Not paginated: this is meant for aggregation, not
     * a list screen.
     */
    public function unpaid(): Collection
    {
        return Invoice::query()
            ->with(['lines', 'customer'])
            ->where('status', '!=', InvoiceStatus::Paid)
            ->orderBy('invoice_date')
            ->get();
    }

    /**
     * Issued or paid invoices (i.e. not draft) — a draft has no statutory
     * effect, so this is the source Compliance's GSTR-1 report reads from.
     * Not paginated: this is meant for aggregation, not a list screen.
     */
    public function issued(): Collection
    {
        return Invoice::query()
            ->with(['lines.item', 'customer'])
            ->where('status', '!=', InvoiceStatus::Draft)
            ->orderBy('invoice_date')
            ->get();
    }

    /**
     * @param  array{sales_order_id: int, invoice_date: string, due_date?: string, notes?: string, lines: array<int, array{sales_order_line_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): Invoice
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = SalesOrder::with('lines')->findOrFail($data['sales_order_id']);

            // A cancelled order is closed to billing (Phase 3.5): nothing
            // left against it and nothing may be invoiced against it. The
            // same exception (→ 422) the delivery path raises for its guard.
            if ($order->status === SalesOrderStatus::Cancelled) {
                throw InvalidStatusTransitionException::make('sales order', $order->status->value, 'invoiced');
            }

            // Derive customer_id from the sales order server-side rather than
            // trusting a client-supplied value — mirrors how the GRN flow
            // derives item_id from the PO line rather than the request body.
            $invoice = Invoice::create([
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'status' => InvoiceStatus::Draft,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($data['lines'] as $lineData) {
                // Form request validation ties sales_order_line_id to this
                // sales_order_id, so a miss here means a genuine bug, not
                // a normal user error — let it fail loudly.
                $soLine = $order->lines->firstWhere('id', $lineData['sales_order_line_id']);

                $invoice->lines()->create([
                    'sales_order_line_id' => $soLine->id,
                    'item_id' => $soLine->item_id,
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                ]);
            }

            return $this->decorate($invoice);
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'invoice',
                $invoice->status->value,
                InvoiceStatus::Issued->value,
            );
        }

        $invoice->update(['status' => InvoiceStatus::Issued]);

        // Decorated like a list row: the Sales entry the model event just
        // queued (TallySyncEventServiceProvider) is already on the response.
        return $this->decorate($invoice);
    }

    /**
     * Every filter of ListInvoicesRequest. `q` matches the invoice number
     * in any spelling ("INV-3", "inv 3", "3") or the customer's name or
     * code — never notes.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['sales_order_id'])) {
            $query->where('sales_order_id', (int) $filters['sales_order_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->query->applyDateRange($query, 'invoice_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'INV');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('invoices.id', $id);
                }
                $any->orWhereHas('customer', fn (Builder $customer) => $this->query->whereCustomerMatches($customer, $term));
            });
        }
    }

    /** The relations the resource prints plus the TallyLink, on ONE invoice. */
    private function decorate(Invoice $invoice): Invoice
    {
        $invoice->load(self::WITH);
        $this->trace->decorateInvoices([$invoice]);

        return $invoice;
    }
}
