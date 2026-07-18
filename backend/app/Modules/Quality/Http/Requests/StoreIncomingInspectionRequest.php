<?php

namespace App\Modules\Quality\Http\Requests;

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
            'inspected_quantity' => ['required', 'numeric', 'gt:0'],
            'accepted_quantity' => ['required', 'numeric', 'min:0'],
            'rejected_quantity' => ['required', 'numeric', 'min:0'],
            'inspection_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
