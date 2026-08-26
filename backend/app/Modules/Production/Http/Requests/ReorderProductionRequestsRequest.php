<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /production/requests/reorder — the WHOLE open queue, in the order the
 * factory should work it.
 *
 * `ordered_ids` has to name every open request, and the service refuses with
 * the missing ones named when it does not. Validation only checks the SHAPE
 * (a non-empty list of positive integers, no duplicates): whether the list
 * covers the queue is a fact about rows that can change between this
 * validation and the write, so it is judged inside reorder()'s locking
 * transaction, never here.
 */
class ReorderProductionRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ordered_ids.required' => 'Send the whole queue in its new order — a reorder renumbers every open request.',
            'ordered_ids.*.distinct' => 'A request cannot appear twice in one queue order.',
        ];
    }
}
