<?php

namespace App\Modules\CRM\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /crm/opportunities — sort, page and page size. An empty query string
 * is the newest-first first page every earlier caller (the quotation
 * picker included) still gets.
 */
class ListOpportunitiesRequest extends FormRequest
{
    public const SORTABLE = ['name', 'estimated_value', 'probability', 'expected_close_date', 'stage'];

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
