<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quality handing a completed batch back to the floor for correction.
 *
 * THE REASON IS REQUIRED, and it is the whole payload. A batch that reappears
 * in the production queue with no explanation is a batch the supervisor will
 * re-submit unchanged — they cannot fix what nobody told them was wrong. This
 * is also the only record of why quality unwound a check they had already
 * made, which is exactly the kind of action that must never be reconstructible
 * only from a stock movement.
 */
class ReturnBatchToProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's module:quality guard (POST ⇒ quality.manage), the same
        // mechanism StoreBatchQualityCheckRequest relies on. WHICH batches may
        // be returned is a transition rule, and transition rules live in the
        // service beside the approval gates they mirror.
        return true;
    }

    public function rules(): array
    {
        return [
            // A short minimum on purpose: "no" and "." are not reasons, and
            // this text is the only instruction the floor gets.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say what production has to correct — the supervisor sees this and nothing else.',
            'reason.min' => 'Say what production has to correct — the supervisor sees this and nothing else.',
        ];
    }
}
