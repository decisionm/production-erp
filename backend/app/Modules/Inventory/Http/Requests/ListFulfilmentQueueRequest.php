<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Services\FulfilmentQueueService;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/fulfilment/queue — THE STORE'S FULFILMENT QUEUE.
 *
 * Nothing is required: an empty query string is every line that still needs
 * somebody, over-reserved rows first.
 *
 * `state` NARROWS; ITS ABSENCE IS NOT "EVERYTHING" (S16). With no state the
 * queue hides `fully_allocated` lines, because a covered line needs no
 * action and a queue where most rows need nothing stops being read. Naming
 * the state is how they are asked for — `?state=fully_allocated` — so the
 * default hides nothing that cannot be reached.
 *
 * A state that does not exist is a 422 rather than a silently empty queue: a
 * store looking at an empty screen concludes there is no work.
 *
 * `sort` (03-Sep-2026) orders the queue on the two REAL columns of its base
 * query — the order number (sales_order_id) and the ordered quantity — plus
 * the line id. Every other column is computed per row (reserved, shortfall,
 * free, state) and has no server order. Absent, the queue keeps its own
 * order: over-reserved first, then by order and line (S8).
 */
class ListFulfilmentQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'nullable', Rule::in(FulfilmentQueueService::STATES)],
            'sort' => ListSort::rule(FulfilmentQueueService::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.FulfilmentQueueService::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
