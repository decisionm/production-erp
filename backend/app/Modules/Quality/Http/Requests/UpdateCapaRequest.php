<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\HRMS\Http\Requests\Rules\SelectableEmployee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'problem_statement' => ['sometimes', 'string'],
            'root_cause' => ['nullable', 'string'],
            'corrective_action' => ['nullable', 'string'],
            'preventive_action' => ['nullable', 'string'],
            'owner' => ['nullable', 'integer', SelectableEmployee::rule()],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
