<?php

namespace App\Modules\Production\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/shifts — `active` (the inline filter the controller always
 * read) plus the shared sort / page contract (ListSort). The list never read
 * `per_page` before (every caller got the first 20); it does now.
 */
class ListShiftsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    /** Besides id: the columns of `shifts` the master can order on. */
    public const SORTABLE = ['name', 'start_time'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
