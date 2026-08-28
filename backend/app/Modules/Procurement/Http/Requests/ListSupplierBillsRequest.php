<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Http\Requests\Rules\EnumOrList;
use App\Modules\Procurement\Models\Enums\SupplierBillStatus;
use Illuminate\Validation\Rule;

/**
 * GET /procurement/supplier-bills — the shared grammar: status one value
 * or a list, `q` as "BILL-12" / the vendor's own invoice number / the
 * vendor's name or code, dates on bill_date.
 */
class ListSupplierBillsRequest extends ListProcurementDocumentsRequest
{
    protected function sortableColumns(): array
    {
        return ['bill_date'];
    }

    protected function documentRules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', new EnumOrList(SupplierBillStatus::class)],
            'status.*' => [Rule::enum(SupplierBillStatus::class)],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
