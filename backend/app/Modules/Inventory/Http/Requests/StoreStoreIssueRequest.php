<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Raising a store issue — the handover of material to production.
 *
 * `lines` may be an EMPTY ARRAY on purpose: resin is handed over by scanning
 * bags onto an open issue, so the header exists before any quantity does.
 *
 * material_request_id / material_request_line_id are validated by TABLE
 * name, not by a model class: the request tables are built by a parallel
 * workstream of the same phase, and `exists:` needs no namespace. Both are
 * nullable — the store may also record a handover made against a verbal ask,
 * and refusing that only pushes the record back off the system.
 *
 * ELIGIBILITY IS NOT APPLIED THE SAME WAY ON BOTH SIDES, and the asymmetry is
 * the point. The REQUEST side gates a NEW ask: may the floor ask for this at
 * all? The ISSUE side fulfils an ask that was ALREADY accepted. Re-gating the
 * fulfilment would mean that switching a material off — which Q56 explicitly
 * invites the owner to do while pruning the deliberately over-broad backfill —
 * would refuse a handover for material that is already on the trolley, with
 * the request open and nothing wrong with it. History must stay ISSUABLE, not
 * merely readable.
 *
 * So: a line that names a request line is checked against THAT LINE — it must
 * name the same item. A line that names none is a fresh handover and carries
 * the full eligibility rule, because there is no earlier decision to honour.
 *
 * Matching the item to the request line also closes a hole that predates this
 * class: nothing cross-checked them, so an issue could name item X against a
 * request line for item Y and the request's fulfilment would credit Y.
 */
class StoreStoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_request_id' => ['nullable', 'integer'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            'issued_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['present', 'array'],
            'lines.*.material_request_line_id' => ['nullable', 'integer'],
            'lines.*.quantity_requested' => ['nullable', 'numeric', 'gt:0'],
            // The floor of the rule, applied to EVERY line. It was a bare
            // `exists:items,id`, which carried neither the soft-delete guard
            // the request side has always had nor anything else. A deleted
            // item is never issuable, whatever it is being issued against.
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $itemId = isset($line['item_id']) ? (int) $line['item_id'] : null;

                if ($itemId === null || $itemId <= 0) {
                    continue; // the base rules already said so
                }

                $requestLineId = isset($line['material_request_line_id']) && $line['material_request_line_id'] !== null
                    ? (int) $line['material_request_line_id']
                    : null;

                if ($requestLineId !== null) {
                    // FULFILLING AN ACCEPTED ASK. The only question is whether
                    // this is the material that was asked for.
                    $asked = DB::table('material_request_lines')->where('id', $requestLineId)->value('item_id');

                    if ($asked !== null && (int) $asked !== $itemId) {
                        $validator->errors()->add(
                            "lines.{$index}.item_id",
                            'This line hands over a different material from the one the request asked for.',
                        );
                    }

                    continue;
                }

                // A FRESH HANDOVER against no request. Nothing decided this
                // earlier, so the full eligibility rule applies.
                $eligible = DB::table('items')
                    ->where('id', $itemId)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('is_production_input', true)
                    ->exists();

                if (! $eligible) {
                    $validator->errors()->add(
                        "lines.{$index}.item_id",
                        'This item is not configured as a material that may be issued to production.',
                    );
                }
            }
        });
    }
}
