<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A GOODS RECEIPT MAY NOT LAND IN PRODUCTION/WIP — not a policy this
     * request invents, but DEC-20260817-001's own definition applied:
     * Production/WIP is "the inventory location holding material that has
     * been physically issued to production but is not yet consumed". A
     * purchase arriving from a vendor has not been issued to anything, so
     * receiving it there would book an issue that never happened, and the
     * 28-Aug live walk found the picker offering exactly that (finding 4).
     * Every other active warehouse stays selectable: which STORE receives
     * which material is the receiver's call, not a rule an agent writes
     * (Q59/Q64 territory).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $warehouseId = $this->input('warehouse_id');
            if (! is_numeric($warehouseId)) {
                return;
            }

            $wipId = app(ProductionWipLocationResolver::class)->warehouseId();
            if ($wipId !== null && (int) $warehouseId === $wipId) {
                $validator->errors()->add(
                    'warehouse_id',
                    'Goods cannot be received into the Production/WIP location — it holds material already issued '
                    .'to production (DEC-20260817-001). Receive into a store; the store issues to production later.',
                );
            }

            $this->requireLotsOnWeighedArrivals($validator);
        });
    }

    /**
     * EVERY WEIGHED ARRIVAL IS COUNTED AT THE GATE (owner decision,
     * 31-Aug-2026, answering Q77).
     *
     * Until this rule the lots block was optional, and the consequence was
     * not that traceability was partial — it was that traceability had never
     * produced a single row. Nothing was mandatory, so nothing was entered,
     * so incoming QC (which acts on BAGS) had nothing to hold, and the whole
     * chain sat inert while reading as switched on.
     *
     * WHY IT REACHES ONLY THE WEIGHED MATERIALS, WHICH IS NARROWER THAN THE
     * DECISION SAYS. The owner's answer was "every purchased material". The
     * lot machinery cannot do that today and the gap is not cosmetic:
     * GoodsReceiptService::553 refuses a lots block outright for anything
     * that is not kg-measured ("Bag lots are only supported for items
     * measured in kg"), and the reconciliation underneath it is arithmetic in
     * KILOGRAMS — bag_weight_kg x bag_count must equal the received line
     * quantity, and a nominal or per-bag weight is mandatory.
     *
     * Applied literally, then, a counted material would be REQUIRED to carry
     * a block the service REFUSES: cartons, trays and film could not be
     * received at all, by either door. The only way to satisfy both rules
     * would be to invent a kilogram weight for a carton, which puts a
     * fictional weight into the stock ledger and into Tally.
     *
     * So this covers the materials the machinery was built for — resin and
     * masterbatch, the ones that actually arrive in weighed bags — and
     * counted packaging keeps arriving exactly as it does today. Extending
     * the rule to counted materials needs a unit-aware lot (bag_count as a
     * COUNT, no weight, and an answer to what carries the barcode when one
     * "bag" is a shrink-wrapped bundle of 500 cartons). That is a model
     * change and an owner question, not something to assume here.
     */
    private function requireLotsOnWeighedArrivals(Validator $validator): void
    {
        if (! config('production.traceability_enabled')) {
            return;
        }

        foreach ((array) $this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['lots'] ?? null) !== null) {
                continue;
            }

            if (! Item::isKgUom($this->uomForLine($line))) {
                continue;
            }

            $validator->errors()->add(
                "lines.{$index}.lots",
                'Record what physically arrived for this material: how many bags, and their weight. '
                .'Weighed materials are counted at the gate now, so this cannot be left blank.',
            );
        }
    }

    /**
     * The unit of the item behind one arrival line, whichever way the line
     * names it — a receipt line carries a purchase order line, or an item
     * directly. Returns null when neither resolves, and a null unit is not
     * kg, so an unresolvable line is left to the rules that already refuse it
     * rather than being handed a second, more confusing refusal.
     */
    private function uomForLine(array $line): ?string
    {
        if (is_numeric($line['purchase_order_line_id'] ?? null)) {
            return DB::table('purchase_order_lines')
                ->join('items', 'items.id', '=', 'purchase_order_lines.item_id')
                ->where('purchase_order_lines.id', (int) $line['purchase_order_line_id'])
                ->value('items.uom');
        }

        if (is_numeric($line['item_id'] ?? null)) {
            return DB::table('items')->where('id', (int) $line['item_id'])->value('uom');
        }

        return null;
    }

    public function rules(): array
    {
        return [
            // New clients send this for safe replay. Optional keeps an
            // already-open pre-deployment browser/API client able to receive
            // stock while the new frontend rolls out.
            'receipt_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            // WS-B: an arrival cannot be booked into a retired store.
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'integer',
                Rule::exists('purchase_order_lines', 'id')
                    ->where('purchase_order_id', $this->input('purchase_order_id')),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            // Arrival references (owner-confirmed): the Receipt Note reference
            // is recorded at physical arrival; both default deterministically
            // when blank so every arrival stays referenceable.
            'receipt_note_reference' => ['nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            // Edited allocation preview — omitted means oldest-due-first.
            'lines.*.schedule_allocations' => ['sometimes', 'array', 'min:1'],
            'lines.*.schedule_allocations.*.purchase_order_schedule_id' => ['required_with:lines.*.schedule_allocations', 'integer', 'exists:purchase_order_schedules,id'],
            'lines.*.schedule_allocations.*.quantity' => ['required_with:lines.*.schedule_allocations', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            // Still 'sometimes' HERE because whether it is required depends
            // on the item's unit, which a static rule cannot see. The
            // requirement is enforced in withValidator() above — see the note
            // there for why it reaches only the weighed materials.
            'lines.*.lots' => ['sometimes', 'array', 'min:1'],
            'lines.*.lots.*.supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'lines.*.lots.*.bag_count' => ['required_with:lines.*.lots', 'integer', 'min:1', 'max:2000'],
            'lines.*.lots.*.bag_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999', new PlainDecimal],
            'lines.*.lots.*.total_received_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.lots.*.barcodes' => ['nullable', 'array'],
            'lines.*.lots.*.barcodes.*' => [
                'required',
                'string',
                'max:64',
                'distinct',
            ],
            'lines.*.lots.*.bag_weights' => ['nullable', 'array'],
            'lines.*.lots.*.bag_weights.*' => ['required', 'numeric', 'gt:0', 'max:99999999', new PlainDecimal],
            'lines.*.lots.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * The lots refusal in the STORE'S words. Laravel's default is "The
     * lines.0.lots field is required", which names a JSON path — a
     * storekeeper standing at the gate with a delivery cannot act on that,
     * and the whole point of making the block mandatory is that they act on
     * it at every arrival.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.lots.*.bag_count.required_with' => 'Say how many bags or packages arrived for this material.',
        ];
    }
}
