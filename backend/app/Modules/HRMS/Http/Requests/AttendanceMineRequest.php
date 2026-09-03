<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /hrms/attendance/me — the caller's OWN days over one range.
 *
 * It takes no employee: who is asking is who is answered for, which is the
 * whole of its authorisation. There is deliberately no way to name somebody
 * else here — that read is `attendance/person`, and it is inside the HRMS
 * permission gate.
 */
class AttendanceMineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
