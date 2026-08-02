<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductStandardsWorkspaceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Product Standards workspace's query string.
 *
 * Nothing here is required and nothing here refuses a request outright except
 * a value that could only be a mistake — a page of standards is a screen a
 * live factory reads all day, and a 422 in place of the product master is a
 * worse answer than a sensible default.
 *
 * `per_page` is deliberately NOT validated against the offered sizes: it is
 * clamped in the service instead, so an old tab asking for 200 gets 100 rows
 * and a working pager rather than an error.
 */
class IndexProductionStandardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'nullable', Rule::in([
                ProductStandardsWorkspaceService::VIEW_READY,
                ProductStandardsWorkspaceService::VIEW_INCOMPLETE,
                ProductStandardsWorkspaceService::VIEW_ALL,
            ])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'missing_tally' => ['sometimes', 'boolean'],
            'packing_mode' => ['sometimes', 'nullable', Rule::in([
                ProductionStandardPackaging::MODE_POUCH,
                ProductionStandardPackaging::MODE_TRAY,
                ProductionStandardPackaging::MODE_DIRECT_BOX,
            ])],
            'work_center_id' => ['sometimes', 'nullable', 'integer', 'exists:work_centers,id'],

            // The filters this endpoint already answered, kept because links
            // in the wild carry them — the Start Batch "configure this
            // product" return in particular.
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'item_id' => ['sometimes', 'nullable', 'integer'],
            'matched_only' => ['sometimes', 'boolean'],

            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
