<?php

namespace App\Modules\Quality\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncomingInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goods_receipt_note_line_id' => ['required', 'integer', 'exists:goods_receipt_note_lines,id'],
            // PlainDecimal, not bare `numeric`: these three go straight into
            // bcmath in IncomingInspectionService, and bcmath does not accept
            // exponent notation. `numeric` passes "1e3", which then reaches
            // bcadd as a malformed number — a 500 on a quality desk rather
            // than a refusal a person can act on. The same rule guards every
            // other quantity this app takes.
            'inspected_quantity' => ['required', 'numeric', 'gt:0', new PlainDecimal],
            'accepted_quantity' => ['required', 'numeric', 'min:0', new PlainDecimal],
            'rejected_quantity' => ['required', 'numeric', 'min:0', new PlainDecimal],
            'inspection_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
