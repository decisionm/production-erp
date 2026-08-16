<?php

namespace App\Modules\Sales\Http\Requests;

/** GET /sales/deliveries — the shared filters plus sales_order_id; from/to are factory days on delivered_date; sorts on delivered_date. */
class ListDeliveriesRequest extends ListSalesDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['delivered_date'];
    }

    protected function documentRules(): array
    {
        return [
            'sales_order_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
