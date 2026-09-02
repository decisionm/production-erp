<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /maintenance/schedules — the existing asset_id filter plus sort, page
 * and page size (03-Sep-2026). An unknown sort column is refused.
 */
class ListMaintenanceSchedulesRequest extends FormRequest
{
    /** The columns the list may sort on, besides id — each one a real column of `maintenance_schedules`. */
    public const SORTABLE = ['name', 'frequency_days', 'next_due_date'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
