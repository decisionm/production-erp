<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999999', new PlainDecimal],

            // WHAT CONDITION IT CAME BACK IN. Optional, and a missing value
            // reads as `good` rather than being refused: every caller written
            // before this column existed is recording a return of usable
            // material, and refusing them would close the return door over a
            // field the factory has only just been asked for.
            'lines.*.quality_state' => ['nullable', 'string', Rule::in(ReturnedQualityState::values())],
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
                // BOTH UNITS ARE CONSULTED, and that is the correction. A first
                // attempt read the master's `items.uom`; a second read the
                // handover's `store_issue_lines.uom`. Each closed the hole
                // facing one way and opened it facing the other, because the
                // two CAN disagree — and not only through a human edit:
                // ItemService::upsertFromTally overwrites `items.uom` from
                // Tally's BASEUNITS on every masters pull, unattended.
                //
                // So a fraction is refused if EITHER reading says the material
                // is counted. A fraction of a counted thing is meaningless
                // under either reading, and the stricter one is the safe one.
                $line = DB::table('store_issue_lines as l')
                    ->join('items as i', 'i.id', '=', 'l.item_id')
                    ->where('l.id', $lineId)
                    ->first(['l.uom as line_uom', 'i.uom as item_uom', 'l.quantity_issued', 'l.quantity_returned']);

                if ($line === null) {
                    continue; // the base `exists` rule already said so
                }

                $counted = collect([$line->line_uom, $line->item_uom])
                    ->filter(fn ($uom) => trim((string) $uom) !== '')
                    ->contains(fn ($uom) => ! MeasurementType::forUom($uom)->permitsFractions());

                if (! $counted) {
                    continue;
                }

                $isWhole = bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) === 0;

                if ($isWhole) {
                    continue;
                }

                // BUT EVERYTHING OUTSTANDING MAY ALWAYS COME BACK. If a
                // fractional quantity is already standing on the floor —
                // because it was issued before this rule, or because the unit
                // was reclassified afterwards — refusing to accept it back
                // would strand it there for ever. A refusal that traps stock
                // is worse than the state it is objecting to.
                $outstanding = bcsub(
                    (string) ($line->quantity_issued ?? '0'),
                    (string) ($line->quantity_returned ?? '0'),
                    4,
                );

                if (bccomp((string) $quantity, $outstanding, 4) === 0) {
                    continue;
                }

                $named = trim((string) $line->line_uom) !== '' ? $line->line_uom : $line->item_uom;

                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "This material is measured in {$named} — a whole number of items. Return a whole number, or the whole {$outstanding} still outstanding.",
                );
            }
        });
    }
}
