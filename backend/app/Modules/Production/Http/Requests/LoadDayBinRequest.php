<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoadDayBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Scanner guns / camera scans send the barcode; the pick-list
            // UI sends the id. Exactly one of the two — `prohibits` rejects
            // a request carrying both, required_without one carrying neither.
            'material_bag_id' => ['required_without:barcode', 'prohibits:barcode', 'nullable', 'integer', 'exists:material_bags,id'],
            'barcode' => ['required_without:material_bag_id', 'nullable', 'string', 'exists:material_bags,barcode'],
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'shift_production_entry_id' => ['nullable', 'integer', 'exists:shift_production_entries,id'],
            // Absent = full-bag scan (the bag's whole remaining_kg);
            // present = weighed partial load (Vincent Q6 stays open).
            'quantity_kg' => ['nullable', 'numeric', 'gt:0'],
            'override_fifo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            // A scanner gun mistyping (or a bag from a never-registered lot)
            // must read as exactly what it is, not a generic "invalid".
            'barcode.exists' => 'Unknown bag barcode — no registered bag carries this code.',
            'material_bag_id.prohibits' => 'Send either material_bag_id or barcode, not both.',
        ];
    }
}
