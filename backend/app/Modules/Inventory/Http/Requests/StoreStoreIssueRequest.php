<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Was a bare `exists:items,id` — it carried NEITHER the
            // soft-delete guard NOR the is_active guard that the request side
            // has always had, so the store could issue an archived or deleted
            // item that the floor could not even ask for. Brought level with
            // the request side, eligibility included: the same material rule
            // governs both halves of the flow.
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')
                ->whereNull('deleted_at')->where('is_active', true)->where('is_production_input', true)],
            'lines.*.from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
