<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\HRMS\Http\Requests\Rules\SelectableEmployee;
use Illuminate\Foundation\Http\FormRequest;

class StoreCapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'non_conformance_report_id' => ['nullable', 'integer', 'exists:non_conformance_reports,id'],
            'title' => ['required', 'string', 'max:255'],
            'problem_statement' => ['required', 'string'],
            'owner' => ['nullable', 'integer', SelectableEmployee::rule()],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
