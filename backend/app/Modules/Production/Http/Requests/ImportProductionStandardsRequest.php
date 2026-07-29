<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductionStandardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * dry_run defaults to TRUE. `exact_only` (also default true) skips any
     * row whose product name does not resolve to exactly one item, and any
     * row carrying an unresolved ambiguity — so a production import cannot
     * quietly create standards nobody has checked.
     */
    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'exact_only' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.sl_no' => ['nullable'],
            'rows.*.product' => ['required', 'string'],
            'rows.*.cavities' => ['nullable'],
            'rows.*.unit_weight_grams' => ['nullable'],
            'rows.*.cycle_time' => ['nullable'],
            'rows.*.nos_per_pouch' => ['nullable'],
            'rows.*.pouch_nos_per_box' => ['nullable'],
            'rows.*.nos_per_tray' => ['nullable'],
            'rows.*.tray_nos_per_box' => ['nullable'],
        ];
    }
}
