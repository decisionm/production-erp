<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use App\Modules\Inventory\Services\ItemIdentityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/identity/items — the item master filtered to what looks
 * wrong with it.
 *
 * Nothing is required: no `warning` is every item tripping ANY class, which
 * is what the review screen opens on. A `warning` that is not one of the
 * eight stable keys is a 422 rather than a silently empty table — a
 * mistyped filter that renders "nothing wrong here" is the one wrong answer
 * this endpoint must never give.
 */
class ListItemWarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warning' => ['sometimes', 'nullable', Rule::enum(ItemIdentityWarning::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ItemIdentityService::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
