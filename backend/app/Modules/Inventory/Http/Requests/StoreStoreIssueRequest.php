<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Raising a store issue — the handover of material to production.
 *
 * `lines` may be an EMPTY ARRAY on purpose: resin is handed over by scanning
 * bags onto an open issue, so the header exists before any quantity does.
 *
 * material_request_id / material_request_line_id are validated by TABLE
 * name, not by a model class: the request tables are built by a parallel
 * workstream of the same phase, and `exists:` needs no namespace. Both are
 * nullable — the store may also record a handover made against a verbal ask,
 * and refusing that only pushes the record back off the system.
 */
class StoreStoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_request_id' => ['nullable', 'integer'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            'issued_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['present', 'array'],
            'lines.*.material_request_line_id' => ['nullable', 'integer'],
            'lines.*.quantity_requested' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
