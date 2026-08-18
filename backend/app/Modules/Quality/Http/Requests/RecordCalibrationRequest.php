<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\Quality\Models\Enums\MeasuringInstrumentStatus;
use App\Modules\Quality\Models\MeasuringInstrument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordCalibrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calibrated_date' => ['required', 'date'],
            'certificate_number' => ['nullable', 'string', 'max:255'],
            'result' => ['required', Rule::in(['pass', 'fail', 'adjusted'])],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * WS-B (audit 17-Aug-2026 §1): `measuring_instruments.status` was set and
     * filtered nowhere, so a RETIRED gauge could still be calibrated. The
     * instrument arrives as a route parameter rather than a body field, so
     * the refusal is an after-check keyed on the route segment's name —
     * there is no `exists:` rule here to widen. Calibration records already
     * on a retired instrument are untouched and still read back.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $instrument = $this->route('instrument');

            if ($instrument instanceof MeasuringInstrument
                && $instrument->status === MeasuringInstrumentStatus::Retired
            ) {
                $validator->errors()->add('instrument', sprintf(
                    '"%s" is a retired instrument, so no new calibration can be recorded against it.',
                    (string) $instrument->name,
                ));
            }
        });
    }
}
