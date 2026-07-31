<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Active-scoped, like the warehouse and operator rules below. A
            // bare exists() accepted a RETIRED shift, and the rule against it
            // lived only in the browser's shift picker — so any client, or a
            // replayed request, could file a batch against a shift the factory
            // no longer runs. Its start/end times then drive the shift-aware
            // production date and the Tally voucher's period, so the batch
            // lands on a date nobody worked.
            'shift_id' => [
                'required',
                'integer',
                Rule::exists('shifts', 'id')->where('is_active', true),
            ],
            // Deliberately NOT active-scoped, unlike the shift above: the
            // readiness gate already refuses an inactive machine and answers
            // with a structured `machine_active` finding the screen can explain.
            // Rejecting it here instead turned that into an anonymous 422 and
            // broke three tests that assert on the finding — the gate is the
            // better mechanism, so this stays a plain existence check.
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            // OPTIONAL, because the floor is never asked where finished
            // bottles go — there is one factory and one place, and
            // FactoryWarehouseResolver answers it server-side (owner, 30-Jul:
            // "there is no need to select any store in any place"). Still
            // VALIDATED when a client does send one: an explicit id must name
            // a live, active warehouse exactly as before, so a retired
            // warehouse is refused rather than silently swapped for the
            // resolved one. Absent or null hands the answer to the service.
            'warehouse_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('is_active', true),
            ],
            'production_date' => ['nullable', 'date'],
            'operator_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where('status', 'active'),
            ],
            // Run actuals, optional at start (may also be set at completion).
            // standard_cycle_time / standard_cavities are deliberately NOT
            // accepted here (or anywhere): they are snapshotted from the item
            // master by the service, and validated() strips any attempt to
            // send them.
            'actual_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // Configurable-production fields. mold/colour narrow which
            // approved configuration governs the run; the *_override pair
            // is the bounded, reasoned deviation from it.
            'mold_id' => ['sometimes', 'nullable', 'integer', 'exists:molds,id'],
            // Which product standard variant and packaging this run uses —
            // asked only when the product genuinely offers a choice.
            'production_standard_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standards,id'],
            'production_standard_packaging_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standard_packagings,id'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cycle_time_override' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'cavities_override' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'scheduled_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],

            // Why this run is starting with less material in the machine's
            // bin than its recipe needs. Deliberately optional and never a
            // gate: the bin bay may be mid-load, and refusing the start
            // would stop a machine the floor can legitimately run. The
            // shortage is a UI-side prompt; the server's job is to RECORD
            // the supervisor's answer, not to arbitrate it.
            'material_shortage_override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Planned downtime known before the run — lowers the adjusted
            // target at Start rather than explaining the gap afterwards.
            'planned_downtime' => ['sometimes', 'array'],
            'planned_downtime.*.downtime_reason_id' => ['required', 'integer', 'exists:downtime_reasons,id'],
            'planned_downtime.*.minutes' => ['required', 'numeric', 'gt:0', 'max:1440'],
            'planned_downtime.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
