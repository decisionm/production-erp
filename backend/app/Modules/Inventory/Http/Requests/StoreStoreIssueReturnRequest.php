<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

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
            'lines.*.quantity' => ['required', 'numeric', new PlainDecimal],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $lineId = isset($line['store_issue_line_id']) ? (int) $line['store_issue_line_id'] : 0;
                $quantity = $line['quantity'] ?? null;

                if ($lineId <= 0 || $quantity === null || ! PlainDecimal::matches($quantity)) {
                    continue;
                }

                // HALF A TRAY DOES NOT COME BACK EITHER.
                //
                // The request door and the issue door both refuse a fractional
                // count; this one did not, and it is the same stock. Returning
                // 0.5 of a counted material put fractional trays in BOTH
                // locations at once — 484.5 in the store and 15.5 on the floor
                // — which is not a state the factory can be in.
                //
                // Pre-existing rather than a regression: this door is unchanged
                // from main. It is closed here because leaving it open would
                // make the unit contract true of two doors out of three, which
                // is not a contract.
                // THE UNIT THE HANDOVER WAS RECORDED IN, falling back to the
                // master's. The screen reads `line.uom` and this read the
                // master's `items.uom`, and an item's unit is editable — so
                // after an edit the two disagreed in BOTH directions,
                // including the one this branch named dangerous (the browser
                // blocking a figure the server would have taken).
                $line = DB::table('store_issue_lines as l')
                    ->join('items as i', 'i.id', '=', 'l.item_id')
                    ->where('l.id', $lineId)
                    ->first(['l.uom as line_uom', 'i.uom as item_uom']);

                $uom = $line === null
                    ? null
                    : (trim((string) $line->line_uom) !== '' ? $line->line_uom : $line->item_uom);

                if ($line === null) {
                    continue; // the base `exists` rule already said so
                }

                $type = MeasurementType::forUom($uom);

                if (! $type->permitsFractions()
                    && bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) !== 0) {
                    $validator->errors()->add(
                        "lines.{$index}.quantity",
                        "This material is measured in {$uom} — {$type->label()}. Return a whole number.",
                    );
                }
            }
        });
    }
}
