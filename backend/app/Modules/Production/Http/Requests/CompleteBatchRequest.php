<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_number' => ['nullable', 'string', 'max:64'],
            'quantity_produced' => ['required', 'numeric', 'gt:0'],
            'quantity_scrap' => ['nullable', 'numeric', 'gte:0'],
            'scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
            'nos_per_tray' => ['nullable', 'integer', 'min:0'],
            'no_of_trays' => ['nullable', 'integer', 'min:0'],
            'nos_per_box' => ['nullable', 'integer', 'min:0'],
            'no_of_box' => ['nullable', 'integer', 'min:0'],
            // Wave A packaging: pouch count (pouch-packed products) and
            // loose pieces left after filling whole containers — previously
            // a frontend-only derivation helper, now persisted.
            'no_of_pouches' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'loose_pieces' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'helper_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],

            // Expected-output engine inputs. standard_cycle_time /
            // standard_cavities are deliberately NOT in these rules — they
            // were snapshotted from the item master at Start Batch and are
            // never writable through any request after; validated() strips
            // any attempt to send them.
            'actual_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'running_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'qc_rejection_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'],

            'material_consumptions' => ['nullable', 'array'],
            'material_consumptions.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'material_consumptions.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'material_consumptions.*.quantity_issued_kg' => ['required', 'numeric', 'gt:0'],

            // Day-bin closing weight per material, same contract as
            // HandoverRequest. This is what makes automatic consumption
            // (opening + loaded − closing − returned) computable on a
            // normal completion instead of only on a handover.
            'closing_day_bin' => ['nullable', 'array'],
            'closing_day_bin.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'closing_day_bin.*.quantity_kg' => ['required', 'numeric', 'gte:0'],

            'scraps' => ['nullable', 'array'],
            'scraps.*.type' => ['required', Rule::in(['rejected_finished_good', 'lumps'])],
            'scraps.*.quantity_nos' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.quantity_kg' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
        ];
    }
}
