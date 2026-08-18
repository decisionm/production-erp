<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
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
            // A GHOST HEADER IS A DANGLING POINTER. Without `exists`, an
            // issue could be filed against request 999999999: stock moved,
            // `store_issues.material_request_id` persisted, and nothing on
            // either side could ever resolve it back to an ask.
            'material_request_id' => ['nullable', 'integer', Rule::exists('material_requests', 'id')],
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
            // `numeric` alone lets `1e3`, `0x1A` and `INF` through to
            // bcmath, which threw a 500 instead of a 422. The sign is left
            // permitted — only the exotic spellings are refused.
            'lines.*.quantity' => ['required', 'numeric', 'regex:/^-?\\d+(\\.\\d+)?$/'],
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

                // THE UNIT IS THE ITEM'S, NOT THE CALLER'S, and a counted thing
                // cannot be handed over in halves.
                //
                // THIS RUNS FOR EVERY LINE, BOTH PATHS, AND THAT PLACEMENT IS
                // THE WHOLE POINT. It used to sit below the accepted-ask branch
                // — every arm of which ends in `continue` — so it was
                // unreachable on the ONE path the store's screen actually uses.
                // A `Nos.` item took 12.5 through it and 12.5 trays landed in
                // Production/WIP. The green suite missed it because every
                // assertion posted without a request line id.
                //
                // Eligibility stays BELOW the branch, deliberately: whether a
                // material may be asked for was settled when the ask was
                // accepted, so history stays issuable. What UNIT a handover is
                // in was never settled by anything — it is a property of the
                // movement happening now, and half a carton is not a thing
                // whoever asked for it.
                //
                // 26 of the factory's 43 Tally stock items are counted, not
                // weighed — trays, master boxes, caps, tape — and across 1,045
                // observed quantities not one `Nos.` or `Pcs.` figure is
                // fractional. Weight keeps its decimals; packing film in
                // kilograms genuinely arrives as 14.700.
                $item = DB::table('items')->where('id', $itemId)->first(['uom']);

                if ($item !== null) {
                    $type = MeasurementType::forUom($item->uom);
                    $quantity = $line['quantity'] ?? null;

                    if ($quantity !== null && $this->isPlainDecimal($quantity)
                        && ! $type->permitsFractions()
                        && bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) !== 0) {
                        $validator->errors()->add(
                            "lines.{$index}.quantity",
                            "This material is measured in {$item->uom} — {$type->label()}. Issue a whole number.",
                        );
                    }

                    // FC-03's lesson, applied to the issue side: "a tape figure
                    // in metres filed as Nos is a different number about a
                    // different thing, and that reached live once". The request
                    // side already refuses a caller-supplied unit; this side
                    // accepted and PERSISTED one, and read it back in place of
                    // the item's.
                    $given = $line['uom'] ?? null;

                    if (is_string($given) && trim($given) !== ''
                        && mb_strtolower(trim($given)) !== mb_strtolower(trim((string) $item->uom))) {
                        $validator->errors()->add(
                            "lines.{$index}.uom",
                            "This material is kept in {$item->uom}. An issue may not name a different unit.",
                        );
                    }
                }

                $requestLineId = isset($line['material_request_line_id']) && $line['material_request_line_id'] !== null
                    ? (int) $line['material_request_line_id']
                    : null;

                if ($requestLineId !== null) {
                    // FULFILLING AN ACCEPTED ASK. The only question left is
                    // whether this is the material that was asked for.
                    $asked = DB::table('material_request_lines as l')
                        ->join('material_requests as r', 'r.id', '=', 'l.material_request_id')
                        ->where('l.id', $requestLineId)
                        ->first(['l.item_id', 'l.material_request_id', 'r.status']);

                    // A LINE THAT NAMES NOTHING IS NOT AN EXEMPTION. Skipping
                    // eligibility is justified only by an earlier decision
                    // that actually exists — without this, quoting any
                    // made-up request line id would have waved a finished
                    // good straight past the rule, because the branch below
                    // ends in `continue`. `material_request_line_id` carries
                    // no `exists:` of its own (the request tables were built
                    // by a parallel workstream), so it is checked here.
                    if ($asked === null) {
                        $validator->errors()->add(
                            "lines.{$index}.material_request_line_id",
                            'This line names a request line that does not exist.',
                        );

                        continue;
                    }

                    // A WITHDRAWN ASK IS NOT AN EARLIER DECISION TO HONOUR.
                    // "History stays issuable" is right for a request the
                    // store still owes; a cancelled one is not owed, and
                    // letting it exempt its item would leave a permanent
                    // loophole attached to a dead row.
                    if ((string) $asked->status === 'cancelled') {
                        $validator->errors()->add(
                            "lines.{$index}.material_request_line_id",
                            'This request was cancelled, so it cannot be issued against.',
                        );

                        continue;
                    }

                    // THE LINE MUST BELONG TO THE REQUEST THIS ISSUE IS FOR.
                    // Without this, a line could name a real request line
                    // while the header named a different request or none at
                    // all — and requestBehind() then returns null, so
                    // applyIssuedQuantities never runs: stock moves, the
                    // pointer is stored, and the request goes on showing the
                    // full quantity owed for ever, against work already done.
                    $header = $this->input('material_request_id');

                    if ($header === null || (int) $header !== (int) $asked->material_request_id) {
                        $validator->errors()->add(
                            "lines.{$index}.material_request_line_id",
                            'This line belongs to a different material request from the one this issue names.',
                        );

                        continue;
                    }

                    if ((int) $asked->item_id !== $itemId) {
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

    /**
     * `numeric` accepts `1e3`, `INF` and `0x1A`; bcmath does not, and threw a
     * 500 out of the quantity guard rather than a 422. A quantity is written
     * the way a storekeeper writes one.
     */
    private function isPlainDecimal(mixed $value): bool
    {
        return is_scalar($value) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1;
    }
}
