<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unused material coming back from production to the store.
 *
 * The quantity is only bounded here by "more than zero"; whether it is more
 * than what is actually standing against the line is the SERVICE's refusal,
 * because only the service holds the line under a lock while it decides.
 * A validator that read the outstanding figure first would be reading it
 * without one.
 */
class StoreStoreIssueReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // NO received_by. On the way OUT the pair matters — the store
            // hand and the production hand are different people and the
            // handover is the record of both. Coming BACK, the person
            // recording the return IS the store hand receiving it, and that
            // is already the authenticated user on every movement written.
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.store_issue_line_id' => ['required', 'integer', 'exists:store_issue_lines,id'],
            'lines.*.quantity' => ['required', 'numeric'],
        ];
    }
}
