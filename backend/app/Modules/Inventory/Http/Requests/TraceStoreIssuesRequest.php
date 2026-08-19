<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The trace read's query string — see ListStoreIssuesRequest for why it exists. */
class TraceStoreIssuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'min:1'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
