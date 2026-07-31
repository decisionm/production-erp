<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Http\Requests\Concerns\ValidatesDowntimeEvents;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shift handover (complete-and-continue): the incoming shift plus the
 * outgoing segment's completion figures and day-bin closing counts.
 * `completion.*` mirrors CompleteBatchRequest — same fields, same limits —
 * and the standard_* snapshot fields stay deliberately absent from both.
 */
class HandoverRequest extends FormRequest
{
    use ValidatesDowntimeEvents;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['nullable', 'date'],
            'operator_id' => ['nullable', 'integer', 'exists:employees,id'],

            'closing_day_bin' => ['nullable', 'array'],
            'closing_day_bin.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'closing_day_bin.*.quantity_kg' => ['required', 'numeric', 'gte:0'],

            'completion' => ['required', 'array'],
            'completion.batch_number' => ['nullable', 'string', 'max:64'],
            'completion.quantity_produced' => ['required', 'numeric', 'gt:0'],
            'completion.quantity_scrap' => ['nullable', 'numeric', 'gte:0'],
            'completion.scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
            'completion.nos_per_tray' => ['nullable', 'integer', 'min:0'],
            'completion.no_of_trays' => ['nullable', 'integer', 'min:0'],
            'completion.nos_per_box' => ['nullable', 'integer', 'min:0'],
            'completion.no_of_box' => ['nullable', 'integer', 'min:0'],
            'completion.no_of_pouches' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'completion.nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'completion.loose_pieces' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'completion.helper_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'completion.notes' => ['nullable', 'string'],
            'completion.actual_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'completion.active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'completion.running_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'completion.qc_rejection_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'],

            'completion.material_consumptions' => ['nullable', 'array'],
            'completion.material_consumptions.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'completion.material_consumptions.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'completion.material_consumptions.*.quantity_issued_kg' => ['required', 'numeric', 'gt:0'],

            'completion.scraps' => ['nullable', 'array'],
            'completion.scraps.*.type' => ['required', Rule::in(['rejected_finished_good', 'lumps'])],
            'completion.scraps.*.quantity_nos' => ['nullable', 'numeric', 'gte:0'],
            'completion.scraps.*.quantity_kg' => ['nullable', 'numeric', 'gte:0'],
            'completion.scraps.*.scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],

            // Downtime logged with the outgoing segment's completion — same
            // shape and cross-checks as CompleteBatchRequest, via the shared
            // trait, so a handover completion can never dodge them.
            ...$this->downtimeEventRules('completion.downtime_events'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $downtime = $this->input('completion.downtime_events');
            if (is_array($downtime) && $downtime !== []) {
                $this->validateDowntimeEvents($validator, $downtime, 'completion.downtime_events');
            }
        });
    }
}
