<?php

namespace App\Modules\CRM\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /crm/leads — sort, page and page size. An empty query string is the
 * newest-first first page every earlier caller still gets. "Last contact"
 * and "next follow-up" live on the latest activity, not on the lead, and
 * are not sortable here.
 */
class ListLeadsRequest extends FormRequest
{
    public const SORTABLE = ['name', 'company', 'email', 'status'];

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
