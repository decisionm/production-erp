<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenMoldChangeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['nullable', 'date'],
            'changed_from_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'changed_to_item_id' => ['required', 'integer', 'exists:items,id'],
        ];
    }
}
