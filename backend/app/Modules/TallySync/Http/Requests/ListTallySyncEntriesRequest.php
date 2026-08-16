<?php

namespace App\Modules\TallySync\Http\Requests;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallySyncQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Tally Sync list's (and its summary's) query string
 * (TALLY-SYNC-CHAIN.md §3 "A query service"). Shared by GET
 * /tally-sync/entries and GET /tally-sync/summary so the header counts can
 * follow whatever the list is filtered to.
 *
 * Nothing here is required. A value that could only be a mistake — a
 * status that does not exist, a date that is not a date, a reversed range —
 * is refused with a 422 rather than silently matching everything or
 * nothing; a page the accountant is reading must never quietly show the
 * wrong rows.
 *
 * `per_page` is deliberately NOT validated here: Controller::perPage clamps
 * it (SyncQueueVisibilityTest pins 999999 → 1000 and 0 → 1), and a clamp
 * is the kinder answer for the one parameter an old tab is most likely to
 * carry a stale value of.
 */
class ListTallySyncEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A hand-typed `?status=failed` is as legitimate as the SPA's
     * `?status[]=failed`, and `?held=true` is what a query string naturally
     * says — both are normalised to what the rules below expect instead of
     * being 422'd for their spelling.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['status', 'category', 'voucher_type'] as $list) {
            if ($this->has($list) && ! is_array($this->input($list)) && $this->input($list) !== null) {
                $normalised[$list] = [$this->input($list)];
            }
        }

        if ($this->has('held') && is_string($this->input('held'))) {
            $held = filter_var($this->input('held'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($held !== null) {
                $normalised['held'] = $held ? 1 : 0;
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        return [
            // 'nullable' throughout: an empty `?status=` (null after the
            // empty-string middleware) is "no filter", not a malformed one.
            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => [Rule::enum(TallySyncStatus::class)],

            // Any catalogue key validates — a Tally-only or planned key is a
            // legitimate question with an honest empty answer (the ERP builds
            // no such entry), not a malformed request.
            'category' => ['sometimes', 'nullable', 'array'],
            'category.*' => [Rule::enum(TallyTransactionCategory::class)],

            'voucher_type' => ['sometimes', 'nullable', 'array'],
            'voucher_type.*' => ['string', 'max:64'],

            // Business-date range on payload->voucher_date, inclusive.
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],

            'q' => ['sometimes', 'nullable', 'string', 'max:120'],

            'shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'work_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'held' => ['sometimes', 'nullable', 'boolean'],

            'direction' => ['sometimes', 'nullable', Rule::in([
                TallySyncEvent::DIRECTION_ERP_TO_TALLY,
                TallySyncEvent::DIRECTION_TALLY_TO_ERP,
            ])],

            'sort' => ['sometimes', 'nullable', Rule::in([TallySyncQueryService::SORT_STATUS_RANK])],
        ];
    }
}
