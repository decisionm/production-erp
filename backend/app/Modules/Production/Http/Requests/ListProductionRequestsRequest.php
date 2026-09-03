<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/requests?status[]=... — the queue AND the look-back read
 * behind the same endpoint.
 *
 * No `status` at all is the default: the floor's open worklist, exactly as
 * before this task (queued + in_progress, priority order) — the controller
 * reads `queue()` for it, unchanged. `status[]=produced&status[]=cancelled`
 * is the owner's 03-Sep-2026 ask to look back at what the queue already did
 * — the controller reads `withStatuses()` for it, newest first, and it is
 * read-only: nothing this request shape can send starts, cancels or
 * completes anything.
 *
 * A status that is not one of the four real ones is a 422, never a silently
 * empty page — the same discipline ListMaterialRequestsRequest uses.
 */
class ListProductionRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(ProductionRequestStatus::class)],
        ];
    }
}
