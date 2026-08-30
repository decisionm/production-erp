<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Material coming back from the production area to a store.
 *
 * A line either names a store issue line (attributed) or a material
 * (unattributed) — see ProductionReturnService for why both doors are one
 * call. Whether the quantity is more than what is actually standing is the
 * SERVICE's refusal in both cases, because only the service holds the
 * balance and the line under a lock while it decides.
 */
class StoreProductionReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // NO received_by, for the same reason the store-issue return has
            // none: the person recording the return IS the store hand taking
            // it back, and that is already the authenticated user on every
            // movement written.
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],

            // NO `->where('is_active', true)` ON THE ITEM, and it is not an
            // oversight next to StoreStockTransferRequest.
            //
            // That request guards a FRESH generic transaction, where an
            // inactive master means "stop using this". This is a RETURN, and
            // a return path home always remains open — deactivating an item
            // closes the front door, it does not strand material on the floor
            // (the same reason StoreStoreIssueReturnRequest validates only
            // that the line exists). Six of the seven materials standing in
            // production on the live instance with no issue behind them are
            // deactivated: an is_active filter here would refuse the exact
            // stock this door was built to bring home.
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.store_issue_line_id' => ['nullable', 'integer', 'exists:store_issue_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999999', new PlainDecimal],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $this->assertAddressed($validator, (int) $index, $line);
                $this->assertWholeIfCounted($validator, (int) $index, $line);
            }
        });
    }

    /** A line has to say what is coming back: a handover line, or a material. */
    private function assertAddressed(Validator $validator, int $index, mixed $line): void
    {
        $hasItem = isset($line['item_id']) && (int) $line['item_id'] > 0;
        $hasIssueLine = isset($line['store_issue_line_id']) && (int) $line['store_issue_line_id'] > 0;

        if (! $hasItem && ! $hasIssueLine) {
            $validator->errors()->add(
                "lines.{$index}.item_id",
                'Name either the material coming back or the store issue line it was handed over on.',
            );

            return;
        }

        // Both given: they must agree. Silently trusting one over the other
        // would book the return against a material the storekeeper did not
        // name — on the wrong balance, with no sign anything went wrong.
        if ($hasItem && $hasIssueLine) {
            $lineItemId = DB::table('store_issue_lines')
                ->where('id', (int) $line['store_issue_line_id'])
                ->value('item_id');

            if ($lineItemId !== null && (int) $lineItemId !== (int) $line['item_id']) {
                $validator->errors()->add(
                    "lines.{$index}.item_id",
                    'That store issue line handed over a different material from the one named here.',
                );
            }
        }
    }

    /**
     * HALF A TRAY DOES NOT COME BACK — the counted-material rule that
     * StoreStoreIssueReturnRequest applies to attributed returns, applied to
     * unattributed ones too. A fraction of a counted thing is meaningless in
     * either location, and this door writes to the same balances.
     *
     * Only `items.uom` is read here: an unattributed line has no handover to
     * carry a second unit. Where a store issue line IS named, that request's
     * stricter both-units reading still governs, because the service hands
     * the line to it untouched.
     */
    private function assertWholeIfCounted(Validator $validator, int $index, mixed $line): void
    {
        $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
        $quantity = $line['quantity'] ?? null;

        if ($itemId <= 0 || $quantity === null || ! PlainDecimal::matches($quantity)) {
            return;
        }

        // Attributed lines are bounded by the store-issue return rules, which
        // include their own fractional check against BOTH units.
        if (isset($line['store_issue_line_id']) && (int) $line['store_issue_line_id'] > 0) {
            return;
        }

        $uom = DB::table('items')->where('id', $itemId)->value('uom');

        if (trim((string) $uom) === '' || MeasurementType::forUom($uom)->permitsFractions()) {
            return;
        }

        if (bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) === 0) {
            return;
        }

        // BUT EVERYTHING STANDING MAY ALWAYS COME BACK. If a fractional
        // quantity is already sitting in production — issued before this rule
        // existed, or reclassified since — refusing it would strand it there
        // for ever. A refusal that traps stock is worse than the state it
        // objects to. The service's residue bound still decides whether that
        // whole figure is actually available.
        $standing = DB::table('stock_balances')
            ->where('item_id', $itemId)
            ->where('warehouse_id', (int) ($this->productionWarehouseId() ?? 0))
            ->value('quantity');

        if ($standing !== null && bccomp((string) $quantity, (string) $standing, 4) === 0) {
            return;
        }

        $validator->errors()->add(
            "lines.{$index}.quantity",
            "This material is measured in {$uom} — a whole number of items. Return a whole number, or the whole "
            .'quantity standing in production.',
        );
    }

    private function productionWarehouseId(): ?int
    {
        return app(ProductionWipLocationResolver::class)->warehouseId();
    }
}
