<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/stock-movements — the ledger's free-text needle.
 *
 * `q` matches the movement's REFERENCE and nothing else: "GRN for PO #4",
 * "MR-12", a shift's handover number — the one column on the ledger a person
 * arrives holding a piece of paper about. Item, warehouse and purpose keep
 * their own filters and their own readers (Controller::filterId /
 * filterEnumList), whose refusal rules are pinned by
 * EveryListFilterRefusesAnArrayTest and left exactly as they were.
 *
 * Substring, case-folded by the driver's LIKE, no index: `reference` is a
 * plain nullable string. A `LIKE '%x%'` over the ledger is a scan, the same
 * class of read as the stock list's item-name search, and it runs beside the
 * indexed item/warehouse predicates when those are set.
 */
class ListStockMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** The trimmed reference needle, or null when the box is empty. */
    public function reference(): ?string
    {
        $value = trim((string) ($this->validated('q') ?? ''));

        return $value === '' ? null : $value;
    }
}
