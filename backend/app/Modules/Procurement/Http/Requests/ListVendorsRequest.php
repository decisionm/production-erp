<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /procurement/vendors — the master's three readers exactly as the
 * controller took them (`q`, `classification[]`, `unclassified`,
 * DEC-20260902-026), plus `sort` (03-Sep-2026) on code, name, state or
 * status (is_active). Absent is name order, as the master always read.
 *
 * `per_page` stays with Controller::perPage: the purchase-order pickers read
 * the whole master at the 1000 ceiling.
 */
class ListVendorsRequest extends FormRequest
{
    public const SORTABLE = ['code', 'name', 'state_code', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'classification' => ['sometimes', 'nullable', 'array'],
            'classification.*' => ['string'],
            'unclassified' => ['sometimes', 'nullable'],
            'sort' => ListSort::rule(self::SORTABLE),
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
