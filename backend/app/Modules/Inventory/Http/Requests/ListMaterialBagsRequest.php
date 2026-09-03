<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/material-bags — the label bench's register.
 *
 * `item_id` and `status` are the two filters the controller always read;
 * they are validated now rather than cast, so a status that is not one of
 * the bag's six states is a 422 instead of a silently empty register.
 *
 * `per_page` is READ for the first time (03-Sep-2026): the bench was fixed
 * at the service's 20 whatever the pager asked for. `sort` orders on the
 * bag's own columns — barcode, original kg, remaining kg, status, registered
 * — and absent keeps the register's order, oldest bag first.
 */
class ListMaterialBagsRequest extends FormRequest
{
    public const SORTABLE = ['barcode', 'original_kg', 'remaining_kg', 'status', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::enum(MaterialBagStatus::class)],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
