<?php

namespace App\Modules\Quality\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /quality/instruments — the gauge register, sorted and paged on the
 * server. `due` (the "Due for calibration only" switch) is the filter the
 * controller used to read inline and keeps exactly its meaning: truthy
 * narrows to gauges whose next calibration is due today or overdue. Bare,
 * the list answers as it always has (next due first, twenty to a page).
 * `last_calibrated_date` is nullable: a never-calibrated gauge sorts last
 * either way.
 */
class ListMeasuringInstrumentsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** The columns the register sorts on besides id. */
    public const SORTABLE = ['code', 'name', 'location', 'next_calibration_due', 'last_calibrated_date', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due' => ['sometimes', 'nullable', 'boolean'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** Only gauges due for calibration? The same reading the controller made inline before. */
    public function dueOnly(): bool
    {
        return $this->boolean('due');
    }

    /** The validated sort, or null for the list's default order. */
    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    /** 1..PER_PAGE_MAX, PER_PAGE_DEFAULT when not asked. */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
