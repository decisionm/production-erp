<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Http\Requests\Rules\EnumOrList;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use Illuminate\Validation\Rule;

/**
 * GET /procurement/purchase-orders — the shared filters plus status;
 * from/to on order_date; sorts on order_date and expected_date.
 *
 * `status` takes ONE value (`?status=sent`, every earlier caller) OR a list
 * (`?status[]=draft&status[]=cancelled`, Phase 6 — the frontend's
 * multi-select over all five statuses). A value that is not a status is
 * refused either way (422 on `status` for the scalar, on `status.N` for a
 * list member) rather than silently matching nothing. The service reads
 * both shapes (a whereIn); the Export Center's form still sees a select.
 */
class ListPurchaseOrdersRequest extends ListProcurementDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['order_date', 'expected_date'];
    }

    protected function documentRules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', new EnumOrList(PurchaseOrderStatus::class)],
            'status.*' => [Rule::enum(PurchaseOrderStatus::class)],
        ];
    }
}
