<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One page of the factory's handwritten production report.
 *
 * The page's own header — date, shift — then one row per machine line. Ten to
 * twelve of them on a real page.
 *
 * DELIBERATELY THIN. Every domain rule this could restate is already enforced by
 * startBatch() and completeBatch(), which this endpoint composes and does not
 * bypass; restating them here would give the floor two sources of truth about
 * what a valid row is, and they would drift. What is validated is the SHAPE — an
 * id that exists, a number that is a number — so a malformed request fails as a
 * request instead of as eleven identical row errors.
 */
class IngestShiftPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('is_active', true)],
            // The same window Start Batch allows, for the same reason: a page is
            // typed up after the shift ran, and often the morning after. The
            // rule lives in StartBatchRequest and is applied there per row —
            // stated here only as shape, never as a second copy of the window.
            'production_date' => ['required', 'date', 'before_or_equal:today'],

            'rows' => ['required', 'array', 'min:1', 'max:30'],
            'rows.*.work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'rows.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'rows.*.quantity_produced' => ['required', 'numeric', 'gte:0'],

            'rows.*.operator_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'rows.*.production_standard_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standards,id'],
            'rows.*.production_standard_packaging_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standard_packagings,id'],
            'rows.*.active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rows.*.colour' => ['sometimes', 'nullable', 'string', 'max:64'],

            'rows.*.quantity_scrap' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            // The paper's own LUMPS column. A separate population from rejection
            // — the workbook states them side by side and never adds them — so
            // it arrives as its own field and becomes its own scrap line.
            'rows.*.lumps_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'rows.*.running_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'rows.*.scrap_reason_id' => ['sometimes', 'nullable', 'integer', 'exists:scrap_reasons,id'],
            'rows.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'rows.*.nos_per_tray' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rows.*.no_of_trays' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rows.*.nos_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rows.*.no_of_box' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'rows.*.material_consumptions' => ['sometimes', 'array'],
            'rows.*.material_consumptions.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'rows.*.material_consumptions.*.quantity_issued_kg' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
