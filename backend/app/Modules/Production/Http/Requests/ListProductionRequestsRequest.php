<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Production\Services\ProductionRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/requests?status[]=... — the queue AND the look-back read
 * behind the same endpoint.
 *
 * No `status` at all is the default: the floor's open worklist, exactly as
 * before this task (queued + in_progress, priority order) — the controller
 * reads `queue()` for it, unchanged, unpaginated. `status[]=produced&status[]=cancelled`
 * is the owner's 03-Sep-2026 ask to look back at what the queue already did
 * — the controller reads `withStatuses()` for it, newest first, PAGINATED
 * (the 28-Aug standing rule), and it is read-only: nothing this request
 * shape can send starts, cancels or completes anything.
 *
 * `page`/`per_page` are read only on the look-back path — `queue()` ignores
 * them, the same way ListMaterialRequestsRequest's siblings do.
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
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ProductionRequestService::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
