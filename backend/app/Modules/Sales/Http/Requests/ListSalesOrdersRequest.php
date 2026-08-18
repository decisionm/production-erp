<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use Illuminate\Validation\Rule;

/** GET /sales/sales-orders — the shared filters plus status; sorts on order_date and expected_date. */
class ListSalesOrdersRequest extends ListSalesDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['order_date', 'expected_date'];
    }

    protected function documentRules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(SalesOrderStatus::class)],
        ];
    }
}
