<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Rules\PlainDecimal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
                $this->assertAddressed($validator, (int) $index, $line);
                $this->assertLedgerPrecision($validator, (int) $index, $line);
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
     * THE LEDGER KEEPS FOUR DECIMAL PLACES, so a fifth is refused rather than
     * dropped.
     *
     * Every quantity on both paths is normalised with `bcadd(..., 4)` before
     * it moves. Without this guard `1.23459` validates, is silently truncated
     * to `1.2345`, and comes back as a 201 — the storekeeper is told a figure
     * came home that is not the figure that moved. The difference is small and
     * that is exactly what makes it dangerous: it never looks wrong, and it
     * accumulates one return at a time.
     *
     * Refused, not rounded. A quantity nobody could have meant to type is a
     * question for the person who typed it.
     */
    private function assertLedgerPrecision(Validator $validator, int $index, mixed $line): void
    {
        $quantity = $line['quantity'] ?? null;

        if ($quantity === null || ! PlainDecimal::matches($quantity)) {
            return;
        }

        $point = strpos((string) $quantity, '.');

        if ($point === false) {
            return;
        }

        $decimals = strlen(rtrim(substr((string) $quantity, $point + 1), '0'));

        if ($decimals > 4) {
            $validator->errors()->add(
                "lines.{$index}.quantity",
                'Quantities are kept to four decimal places. Round this to four before recording it.',
            );
        }
    }

    /**
     * HALF A TRAY DOES NOT COME BACK — the counted-material rule that
     * StoreStoreIssueReturnRequest applies to attributed returns, applied to
     * unattributed ones too. A fraction of a counted thing is meaningless in
     * either location, and this door writes to the same balances.
     *
     * BOTH UNITS ARE CONSULTED WHEN A HANDOVER IS NAMED, and this file used
     * to claim it did not have to be — that `StoreStoreIssueReturnRequest`
     * still governed an attributed line "because the service hands the line
     * to it untouched". THAT WAS FALSE. `ProductionReturnService` calls
     * `StoreIssueService::returnUnused()` — a SERVICE method — so no
     * FormRequest of that other door ever runs on this path, and half a cap
     * could come back through it with a 201. The rule is applied here, on
     * both kinds of line.
     *
     * The two readings CAN disagree, and not only through a human edit:
     * `ItemService::upsertFromTally` overwrites `items.uom` from Tally's
     * BASEUNITS on every masters pull, unattended. So a fraction is refused
     * if EITHER reading says the material is counted.
     */
    private function assertWholeIfCounted(Validator $validator, int $index, mixed $line): void
    {
        $lineId = isset($line['store_issue_line_id']) ? (int) $line['store_issue_line_id'] : 0;
        $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
        $quantity = $line['quantity'] ?? null;

        if ($quantity === null || ! PlainDecimal::matches($quantity)) {
            return;
        }

        $units = $lineId > 0 ? $this->unitsOfHandover($lineId) : $this->unitsOfItem($itemId);

        if ($units === []) {
            return;
        }

        $counted = collect($units)
            ->filter(fn ($uom) => trim((string) $uom) !== '')
            ->contains(fn ($uom) => ! MeasurementType::forUom($uom)->permitsFractions());

        if (! $counted) {
            return;
        }

        if (bccomp((string) $quantity, bcadd((string) (int) $quantity, '0', 4), 4) === 0) {
            return;
        }

        $uom = collect($units)->first(fn ($unit) => trim((string) $unit) !== '');

        // BUT EVERYTHING STANDING MAY ALWAYS COME BACK. If a fractional
        // quantity is already sitting in production — issued before this rule
        // existed, or reclassified since — refusing it would strand it there
        // for ever. A refusal that traps stock is worse than the state it
        // objects to. The service's own bound still decides whether that whole
        // figure is actually available.
        if ($this->isEverythingStanding($lineId, $itemId, (string) $quantity)) {
            return;
        }

        $validator->errors()->add(
            "lines.{$index}.quantity",
            "This material is measured in {$uom} — a whole number of items. Return a whole number, or the whole "
            .'quantity standing in production.',
        );
    }

    /**
     * The units a handover reading has: the line's own, and its item master's.
     *
     * @return array<int, mixed>
     */
    private function unitsOfHandover(int $lineId): array
    {
        $line = DB::table('store_issue_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('l.id', $lineId)
            ->first(['l.uom as line_uom', 'i.uom as item_uom']);

        return $line === null ? [] : [$line->line_uom, $line->item_uom];
    }

    /** @return array<int, mixed> */
    private function unitsOfItem(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $uom = DB::table('items')->where('id', $itemId)->value('uom');

        return $uom === null ? [] : [$uom];
    }

    /**
     * Is this the WHOLE of what is standing — the line's outstanding quantity
     * for an attributed return, the production balance for an unattributed
     * one? Only then may a fraction of a counted material come home.
     */
    private function isEverythingStanding(int $lineId, int $itemId, string $quantity): bool
    {
        if ($lineId > 0) {
            $line = DB::table('store_issue_lines')
                ->where('id', $lineId)
                ->first(['quantity_issued', 'quantity_returned']);

            if ($line === null) {
                return false;
            }

            $outstanding = bcsub(
                (string) ($line->quantity_issued ?? '0'),
                (string) ($line->quantity_returned ?? '0'),
                4,
            );

            return bccomp($quantity, $outstanding, 4) === 0;
        }

        $standing = DB::table('stock_balances')
            ->where('item_id', $itemId)
            ->where('warehouse_id', (int) ($this->productionWarehouseId() ?? 0))
            ->value('quantity');

        return $standing !== null && bccomp($quantity, (string) $standing, 4) === 0;
    }

    private function productionWarehouseId(): ?int
    {
        return app(ProductionWipLocationResolver::class)->warehouseId();
    }
}
