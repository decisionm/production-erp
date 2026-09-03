<?php

namespace App\Modules\Quality\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /quality/batch-quality-queue — the completed batches waiting for
 * their quality check, oldest first, searched and paged on the server.
 *
 * The queue has no status filter to offer: its membership IS a status
 * (pending · completed · unchecked · not sent back), fixed by the service.
 * What a checker types is a batch number, a product or a machine, and
 * that is what `q` matches (ShiftProductionEntryService::whereMatchesTerm).
 * `sort` re-orders the queue on what its columns show — the batch number,
 * the produced count, the production date; absent, the queue is worked
 * front to back as before. A page size outside 1..100 is refused, as on
 * every list.
 *
 * `returned` (03-Sep-2026, Task 2 of "Returned by Quality") narrows the
 * queue's OWN membership to rows that carry at least one entry in
 * config_snapshot['quality_returns'] — it can never widen it. Because the
 * queue already excludes a batch still awaiting the floor's correction
 * (whereAwaitingQualityCheck), the only rows this can ever surface are ones
 * that were sent back AND already re-submitted: a batch back for a second
 * look, not new to the desk. Same `boolean` reading as `due` on the
 * instrument register (ListMeasuringInstrumentsRequest) — anything not
 * literally truthy is no filter.
 */
class ListBatchQualityQueueRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** The columns the queue sorts on besides id. */
    public const SORTABLE = ['batch_number', 'quantity_produced', 'production_date'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'returned' => ['sometimes', 'nullable', 'boolean'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** The search term, trimmed, or null for none. */
    public function term(): ?string
    {
        $term = trim((string) $this->validated('q'));

        return $term === '' ? null : $term;
    }

    /** Only batches that carry a quality return, resubmitted or not? */
    public function returnedOnly(): bool
    {
        return $this->boolean('returned');
    }

    /** The validated sort, or null for the queue's own order (oldest first). */
    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    /** 1..PER_PAGE_MAX, PER_PAGE_DEFAULT when not asked. */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
