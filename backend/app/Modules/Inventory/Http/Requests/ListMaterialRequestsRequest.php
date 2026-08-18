<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Services\MaterialRequestService;
use App\Modules\Procurement\Http\Requests\Rules\EnumOrList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/material-requests — THE STORE'S QUEUE.
 *
 * Nothing is required: an empty query string is the whole queue, newest
 * first. Every documented filter narrows it IN SQL (the service builds one
 * statement), and a value that could only be a mistake — a status that does
 * not exist, a non-date, a reversed range, an unknown sort column, a page
 * size outside 1..1000 — is a 422 rather than a silently empty or silently
 * full list. A key nobody documented is not validated and so not read.
 *
 * `status` takes one value (`?status=submitted`) or a list
 * (`?status[]=submitted&status[]=partially_issued`, the queue's default
 * "still open" view) — the same grammar the Procurement lists use, so the
 * two behave alike for the same people.
 *
 * `q` is the request NUMBER in any spelling ("MR-12", "mr 12", "12") and
 * deliberately nothing else — see the service.
 *
 * The EnumOrList rule is Procurement's, imported rather than copied: it is
 * generic validation infrastructure (a Rule::enum that also admits a list),
 * carries no Procurement meaning, and duplicating it would leave two
 * definitions of one grammar to drift apart.
 */
class ListMaterialRequestsRequest extends FormRequest
{
    /** Besides id: the columns the store's queue may be ordered by. */
    private const SORTABLE = ['requested_at', 'submitted_at', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', new EnumOrList(MaterialRequestStatus::class)],
            'status.*' => [Rule::enum(MaterialRequestStatus::class)],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'work_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in($this->sortOptions())],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.MaterialRequestService::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Production's own screen asking for its unsubmitted drafts. Being
            // ACCEPTED here is not being honoured — the controller grants it
            // only to a caller holding production's permission.
            'include_unsubmitted' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string> */
    private function sortOptions(): array
    {
        $options = ['id', '-id'];
        foreach (self::SORTABLE as $column) {
            $options[] = $column;
            $options[] = "-{$column}";
        }

        return $options;
    }
}
