<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductionConfigurationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * dry_run defaults to TRUE. An import that writes by default is one
     * mis-click from filling the configuration list with rows nobody
     * reviewed; the caller must ask for the write explicitly.
     */
    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.machine' => ['nullable', 'string'],
            'rows.*.item' => ['nullable', 'string'],
            'rows.*.mold' => ['nullable', 'string'],
            'rows.*.colour' => ['nullable', 'string'],
            'rows.*.unit_weight_grams' => ['nullable', 'numeric'],
            'rows.*.cycle_time' => ['nullable', 'numeric'],
            'rows.*.cavities' => ['nullable', 'integer'],
            'rows.*.mapping_id' => ['nullable', 'string'],
            'rows.*.confirmation_status' => ['nullable', 'string'],
            'rows.*.notes' => ['nullable', 'string'],
        ];
    }
}
