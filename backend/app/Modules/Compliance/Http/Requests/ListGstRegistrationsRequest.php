<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /compliance/gst-registrations — sort, page and page size. An empty
 * query string is the primary-first, state-ordered first page every
 * earlier caller still gets.
 */
class ListGstRegistrationsRequest extends FormRequest
{
    public const SORTABLE = ['gstin', 'state_code', 'state_name', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
