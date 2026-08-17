<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use Illuminate\Validation\Rule;

/** GET /procurement/purchase-orders — the shared filters plus status; from/to on order_date; sorts on order_date and expected_date. */
class ListPurchaseOrdersRequest extends ListProcurementDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['order_date', 'expected_date'];
    }

    protected function documentRules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(PurchaseOrderStatus::class)],
        ];
    }
}
