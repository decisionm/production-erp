<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/shift-production-entries — the ONE list, filtered
 * (Phase 5.5, WS-C). There is deliberately no dedicated completed-today
 * endpoint: Completed Today is this list read as
 * `production_date=<today, factory day>&batch_status=completed&per_page=100`,
 * the approval queue is `status=pending`, the dashboard reads it bare.
 *
 * Nothing is required and a key nobody documented is simply not validated
 * and so not read — an old tab's stale query string still loads. A value
 * that could only be a mistake — a non-date, a reversed range, a batch or
 * approval status that does not exist, a page size outside 1..100 — is
 * refused with a 422 rather than silently matching everything or nothing
 * (a bad `status` used to reach the enum's from() and 500).
 *
 * `production_date`, `date_from`, `date_to` are FACTORY DAYS compared on the
 * stored production_date column exactly as the entry was filed: the night
 * shift's 02:00 batch carries the day it started, and that — not the wall
 * clock the reader is standing in — is what a date here matches.
 * 'nullable' throughout: an empty `?production_date=` (null after the
 * empty-string middleware) is "no filter", not a malformed one.
 *
 * `correctable` / `awaiting_correction` (Phase 7, P7-03 (g)): the two
 * work-queue questions, answered in SQL before the page is cut
 * (ShiftProductionEntryService::paginate) — `correctable=1` is every
 * completed batch still pending with no quality check (the frontend's
 * canAmendCompletion, row for row); `awaiting_correction=1` is the subset
 * quality has sent back that the floor has not yet re-submitted
 * (correction.awaiting_correction on the resource, row for row). Boolean
 * flags: 1/true asks; 0/false/absent is "no filter" — never the
 * complement — so an old client that sends nothing reads exactly as before.
 */
class ListShiftProductionEntriesRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'work_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'batch_status' => ['sometimes', 'nullable', Rule::enum(BatchStatus::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(ShiftProductionEntryStatus::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // 1/0 and the words, because a query string carries strings and
            // an axios params object spells a boolean 'true'/'false'.
            'correctable' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'awaiting_correction' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
        ];
    }

    /** A boolean flag filter: true only when asked (1 / true); absent, empty, 0 or false is "no filter". */
    public function flagFilter(string $key): bool
    {
        $value = $this->validated($key);

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** The approval-status filter as an enum, or null for none. */
    public function status(): ?ShiftProductionEntryStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : ShiftProductionEntryStatus::from($status);
    }

    /** The batch-status filter as an enum, or null for none. */
    public function batchStatus(): ?BatchStatus
    {
        $status = $this->validated('batch_status');

        return $status === null ? null : BatchStatus::from($status);
    }

    /** 1..PER_PAGE_MAX, PER_PAGE_DEFAULT when not asked. */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }

    /** A validated integer id filter, or null for none. */
    public function idFilter(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }

    /** A validated factory-day filter (Y-m-d), or null for none. */
    public function dayFilter(string $key): ?string
    {
        $value = $this->validated($key);

        return $value === null ? null : (string) $value;
    }
}
