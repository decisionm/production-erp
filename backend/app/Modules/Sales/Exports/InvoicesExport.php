<?php

namespace App\Modules\Sales\Exports;

use App\Modules\Sales\Http\Requests\ListInvoicesRequest;
use App\Modules\Sales\Http\Resources\InvoiceResource;
use App\Modules\Sales\Services\InvoiceService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * GET /sales/invoices, downloaded: one row per invoice, as InvoiceResource
 * emits it for this reader — number, status, the order it bills, customer,
 * dates, its Sales TallyLink (null while draft: status, voucher number,
 * synced_at), notes — plus `lines_count`.
 */
class InvoicesExport extends SalesExportKind
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function filterRules(): array
    {
        return $this->listRules(new ListInvoicesRequest);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'document_number' => 'document_number',
            'status' => 'status',
            'sales_order_number' => 'sales_order.document_number',
            'customer_code' => 'customer.code',
            'customer_name' => 'customer.name',
            'invoice_date' => 'invoice_date',
            'due_date' => 'due_date',
            'lines_count' => 'lines_count',
            'tally_status' => 'tally.status',
            'tally_voucher_number' => 'tally.voucher_number',
            'tally_synced_at' => 'tally.synced_at',
            'notes' => 'notes',
            'created_at' => 'created_at',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->invoices->cursor($filters) as $invoice) {
            $row = $this->wire(InvoiceResource::make($invoice), $request);
            $row['lines_count'] = count($row['lines'] ?? []);

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->invoices->count($filters);
    }
}
