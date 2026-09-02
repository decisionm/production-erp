<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /compliance/gst-rates — sort, page and page size. An empty query
 * string is the HSN/SAC-ordered first page every earlier caller still gets.
 */
class ListGstRatesRequest extends FormRequest
{
    public const SORTABLE = ['hsn_sac_code', 'description', 'rate_percent', 'is_active'];

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
