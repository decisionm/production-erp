<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /inventory/stock-balances — the stock list's query string.
 *
 * Nothing is required: the bare URL is every item×warehouse balance, item
 * name first. What was validated inline in the controller (`warehouse_id`,
 * `sort`, `direction`) moved here unchanged, and the free-text needle gained
 * the spelling every other list already reads.
 *
 * `q` IS THE NEEDLE; `search` IS ITS OLDER SPELLING. The Procurement, Sales
 * and TallySync lists all read `q`, and the shared list-state hook on the
 * frontend writes `q` to the URL, so the stock list answers it too. `search`
 * stays because clients already send it (InventoryListCompletenessTest pins
 * it) — the two are one needle, one matcher, never two rules. When both are
 * sent `q` wins; an empty box narrows nothing.
 *
 * A list-shaped value (`?q[]=x`) fails `string` and is refused, which is the
 * same direction Controller::scalarQuery refuses in: a filter that fails
 * open answers with MORE than was asked for.
 */
class ListStockBalancesRequest extends FormRequest
{
    /** The columns the list may be ordered by — the service maps each to SQL. */
    public const SORTABLE = ['item', 'warehouse', 'quantity'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }

    /** The trimmed needle — `q` first, then `search` — or null when neither narrows. */
    public function needle(): ?string
    {
        foreach (['q', 'search'] as $key) {
            $value = trim((string) ($this->validated($key) ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function warehouseId(): ?int
    {
        $value = $this->validated('warehouse_id');

        return $value === null ? null : (int) $value;
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'item');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }
}
