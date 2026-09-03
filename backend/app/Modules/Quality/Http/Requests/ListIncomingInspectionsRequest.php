<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /quality/incoming-inspections — the register, searched, sorted and
 * paged on the server. Nothing is required: bare, it answers exactly as it
 * always has (newest first, twenty to a page). A value that could only be
 * a mistake — a result that does not exist, a sort on a column the list
 * does not have, a page size outside 1..100 — is refused with a 422 rather
 * than silently matching everything or nothing.
 *
 * `q` searches what a row shows: the product's sku or name, the arrival's
 * GRN (its number or tracking number) and the Rejections Out reference; a
 * bare number is an inspection or GRN id. See
 * IncomingInspectionService::paginate for the exact matching.
 */
class ListIncomingInspectionsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** The columns the register sorts on besides id — the ones its table shows. */
    public const SORTABLE = ['inspected_quantity', 'accepted_quantity', 'rejected_quantity', 'result', 'inspection_date'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'result' => ['sometimes', 'nullable', Rule::enum(InspectionResult::class)],
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

    /** The result filter as an enum, or null for none. */
    public function result(): ?InspectionResult
    {
        $result = $this->validated('result');

        return $result === null ? null : InspectionResult::from($result);
    }

    /** The validated sort, or null for the list's default order. */
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
