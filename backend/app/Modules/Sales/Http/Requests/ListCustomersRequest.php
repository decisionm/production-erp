<?php

namespace App\Modules\Sales\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /sales/customers — `sort` (03-Sep-2026) on code, name, state or status
 * (is_active), in the ListSort spelling; absent is name order.
 *
 * `per_page` keeps the controller's own clamp (1..200, default 20): the
 * order and opportunity pickers read the master at 200, and a clamp never
 * answers a typo with a 422.
 */
class ListCustomersRequest extends FormRequest
{
    public const SORTABLE = ['code', 'name', 'state_code', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
