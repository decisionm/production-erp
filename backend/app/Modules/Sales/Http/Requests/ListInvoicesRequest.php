<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\Enums\InvoiceStatus;
use Illuminate\Validation\Rule;

/** GET /sales/invoices — the shared filters plus sales_order_id and status; sorts on invoice_date. */
class ListInvoicesRequest extends ListSalesDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['invoice_date'];
    }

    protected function documentRules(): array
    {
        return [
            'sales_order_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::enum(InvoiceStatus::class)],
        ];
    }
}
