<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['lines.item', 'customer', 'salesOrder'])
            ->orderByDesc('id')
            ->paginate($perPage);
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

            return $invoice->load(['lines.item', 'customer', 'salesOrder']);
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

        return $invoice;
    }
}
