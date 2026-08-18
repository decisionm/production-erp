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
            // BACKDATING IS REAL WORK, not an error to be prevented. A shift
            // that ran last night gets entered this morning; a supervisor
            // catching up on paperwork enters three days at once. Refusing it
            // does not make the data truer, it makes the floor file everything
            // under the day they happened to type it — which is exactly what
            // happened before this rule existed, and it lands a shift in the
            // wrong day's Tally voucher and the wrong day's report.
            //
            // So it is allowed, with one rule that is always on and one that is
            // the factory's to choose:
            //
            //   - NEVER THE FUTURE, unconditionally. Production that has not
            //     happened cannot be recorded, and a fat-fingered year is the
            //     likeliest way a batch ends up dated 2027. No caller has a
            //     legitimate reason to file tomorrow's shift.
            //   - a floor, only when configured. It is deliberately OFF by
            //     default: this endpoint is a versioned product surface, and a
            //     hard floor here would refuse the legitimate callers that
            //     backfill history — the migration that seeds last quarter, the
            //     integration that replays a month. The factory's own window is
            //     set in config and enforced in the Start dialog's date picker,
            //     which is where a supervisor's mistyped month actually gets
            //     caught. Adding the floor unconditionally broke 86 existing
            //     tests, every one of them a caller stating a historical date
            //     the contract had always accepted.
            'production_date' => array_filter([
                'nullable',
                'date',
                'before_or_equal:today',
                $this->backdateFloor() ? 'after_or_equal:'.$this->backdateFloor() : null,
            ]),
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
            // WS-B (audit 17-Aug-2026): a RETIRED mould was selectable on the
            // floor. Only `retired` is refused — whether a mould `under_repair`
            // may be scheduled is a factory question nobody has answered, and
            // this rule does not answer it.
            'mold_id' => ['sometimes', 'nullable', 'integer', Rule::exists('molds', 'id')->whereNot('status', 'retired')],
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
            // Active only — the Start Batch picker already filters on
            // is_active (DowntimeReasonService::list), and the rule must
            // agree with it: a stale tab or a direct API call must not be
            // able to plan downtime against a withdrawn reason.
            'planned_downtime.*.downtime_reason_id' => ['required', 'integer', Rule::exists('downtime_reasons', 'id')->where('is_active', true)],
            'planned_downtime.*.minutes' => ['required', 'numeric', 'gt:0', 'max:1440'],
            'planned_downtime.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The earliest production date this request will accept, or null for no
     * floor at all (the default — see the rule above for why).
     *
     * 'month' is the factory's stated answer to "how far back do you need to
     * enter?" (05-Aug). It is read as the 1st of the current month OR a week
     * back, whichever reaches further: on the 2nd of a month a strict month
     * floor would refuse last night's shift, which is the very entry the
     * feature exists to allow.
     */
    private function backdateFloor(): ?string
    {
        $limit = config('production.backdate_limit', 'none');

        if ($limit === 'none' || $limit === null || $limit === '') {
            return null;
        }

        if ($limit === 'month') {
            return min(
                now()->startOfMonth()->toDateString(),
                now()->subWeek()->toDateString(),
            );
        }

        return (int) $limit > 0
            ? now()->subDays((int) $limit)->toDateString()
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Said in floor language, because this is the message a supervisor
            // sees at 6am. The default rule text ("must be a date before or
            // equal to today") does not tell them what to do next.
            'production_date.before_or_equal' => 'This date is in the future. A batch can only be recorded for a day that has already happened.',
            'production_date.after_or_equal' => 'That date is too far back. Production can be entered from '
                .($this->backdateFloor() ?? '').' onwards — check the month is right.',
        ];
    }
}
