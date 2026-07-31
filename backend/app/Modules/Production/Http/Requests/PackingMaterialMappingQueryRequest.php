<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reading the packing-material master: everything, or one kind, or the answer
 * for one spec.
 */
class PackingMaterialMappingQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'spec_kind' => ['sometimes', 'nullable', 'string', Rule::in(PackingMaterialMapping::KINDS)],
            // Matched case- and spacing-insensitively, because the workbook
            // spells one tray "60 ML" on one row and "60ML" on the next.
            'spec_value' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
