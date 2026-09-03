<?php

namespace App\Modules\CRM\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /crm/quotations — sort, page and page size. An empty query string is
 * the newest-first first page every earlier caller still gets.
 */
class ListQuotationsRequest extends FormRequest
{
    public const SORTABLE = ['status', 'quotation_date'];

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
