<?php

namespace App\Modules\Production\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /production/subcontract-orders — the shared sort / page contract (ListSort).
 */
class ListSubcontractOrdersRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    /** Besides id: the columns of `subcontract_orders` the register can order on. */
    public const SORTABLE = ['quantity_planned', 'quantity_received', 'status'];

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
