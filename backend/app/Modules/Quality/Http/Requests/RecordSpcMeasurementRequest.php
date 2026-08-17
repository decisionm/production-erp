<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\Quality\Models\SpcCharacteristic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordSpcMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric'],
            'measured_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * WS-B (audit 17-Aug-2026 §1): `spc_characteristics.is_active` was set
     * and filtered nowhere. A withdrawn characteristic is one the factory has
     * stopped measuring, so it takes no NEW measurement; the measurements
     * already recorded against it stay and still chart. Keyed on the route
     * segment because the characteristic is not a body field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $characteristic = $this->route('spc_characteristic');

            if ($characteristic instanceof SpcCharacteristic && ! $characteristic->is_active) {
                $validator->errors()->add('spc_characteristic', sprintf(
                    '"%s" is no longer an active characteristic, so no new measurement can be recorded against it.',
                    (string) $characteristic->name,
                ));
            }
        });
    }
}
