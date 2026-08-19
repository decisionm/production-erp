<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /inventory/material-requests — the floor raises a request.
 *
 * `work_center_id` is OPTIONAL and, for a common-input (kg-family) item, it
 * is REFUSED — FC-01 / DEC-20260807-006. That refusal is NOT expressed here:
 * it needs the item rows, it must read exactly the same predicate the rest
 * of the codebase uses, and it must be impossible to bypass by calling the
 * service directly. It lives in MaterialRequestService instead, and answers
 * a 422 with `code: common_input_names_no_machine`.
 *
 * `uom` is NOT accepted. The unit is snapshotted from the item (FC-03: a
 * tape figure in metres filed as Nos is a different number about a
 * different thing, and that reached live once). A caller who sends one is
 * simply not read.
 *
 * Only ACTIVE masters may be named: a request against a retired machine,
 * shift or item is a request nobody can fulfil.
 */
class StoreMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'work_center_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_centers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            // ELIGIBILITY IS ENFORCED HERE, not only in the picker. The
            // dropdown used to be fed the whole item master, and the API
            // accepted whatever it was handed — so a finished good could be
            // requested as an input by anyone posting directly, which is why
            // filtering the React list alone would not have been a fix.
            // `is_production_input` is the factory's configuration; the
            // refusal message names the item rather than the column.
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')
                ->whereNull('deleted_at')->where('is_active', true)->where('is_production_input', true)],
            // Stock quantities are decimal everywhere in this codebase, and
            // an ask of zero is not an ask.
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'A material request has to name at least one material.',
            'lines.*.quantity.gt' => 'Ask for a quantity greater than zero.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
                $quantity = $line['quantity'] ?? null;

                // PlainDecimal, not is_numeric: `is_numeric('1e3')` is true
                // and bccomp() below then threw a ValueError — a 500 answering
                // a malformed figure, on the request side, for counted items
                // only (a weight item short-circuits before it). The rule
                // above refuses the spelling too; this keeps the guard and the
                // rule agreeing, which is the whole lesson of this branch.
                if ($itemId <= 0 || $quantity === null || ! PlainDecimal::matches($quantity)) {
                    continue;
                }

                // A COUNTED THING CANNOT BE ASKED FOR IN HALVES. 26 of the
                // factory's 43 Tally stock items are counted rather than
                // weighed, and across 1,045 observed quantities not one
                // `Nos.` or `Pcs.` figure is fractional. Weight keeps its
                // decimals — packing film really does arrive as 14.700 kg.
                $item = DB::table('items')->where('id', $itemId)->first(['uom']);

                if ($item === null) {
                    continue;
                }

                $type = MeasurementType::forUom($item->uom);

                if (! $type->permitsFractions()
                    && bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) !== 0) {
                    $validator->errors()->add(
                        "lines.{$index}.quantity",
                        "This material is measured in {$item->uom} — {$type->label()}. Ask for a whole number.",
                    );
                }
            }
        });
    }
}
