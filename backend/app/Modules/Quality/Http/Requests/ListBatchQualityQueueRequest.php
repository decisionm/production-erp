<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /quality/batch-quality-queue — the completed batches waiting for
 * their quality check, oldest first, searched and paged on the server.
 *
 * The queue has no status filter to offer: its membership IS a status
 * (pending · completed · unchecked · not sent back), fixed by the service.
 * What a checker types is a batch number, a product or a machine, and
 * that is what `q` matches (ShiftProductionEntryService::whereMatchesTerm).
 * A page size outside 1..100 is refused, as on every list.
 */
class ListBatchQualityQueueRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
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

    /** 1..PER_PAGE_MAX, PER_PAGE_DEFAULT when not asked. */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
