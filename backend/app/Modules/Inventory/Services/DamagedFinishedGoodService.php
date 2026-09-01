<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE DOOR IN — a finished good found damaged, on its way to Quality
 * (DEC-20260901-006).
 *
 * WHAT THIS CLASS IS AND IS NOT. It is only the way IN to quality hold.
 * Everything that happens afterwards — what is standing there, confirming the
 * damage, scrapping it, releasing it when Quality looks and the goods are
 * fine — is ReturnedMaterialQualityService, unchanged and not copied. That
 * service works on an item and a location and never cared which kind of item
 * it was, so a damaged bottle and a damaged bag of resin dispose of
 * identically once they are in the hold. Two behaviours come free as a
 * result, and a copy would have drifted on both: a disposition is bounded by
 * what is actually standing, and dispositions move in item order so two of
 * them cannot deadlock.
 *
 * REPORTING IS NOT SCRAPPING, and that is the whole of "to Quality first".
 * This moves the quantity out of the finished-goods store and into quality
 * hold. It removes it from issuable, sellable and dispatchable stock
 * immediately — because balances are per item AND warehouse, so material in
 * the hold is out of the store's figures by construction — but it does NOT
 * take it out of stock. Only Quality's confirmation does that. The Store can
 * therefore stop a damaged box from being sold without being able to write it
 * off, which is the separation the owner asked for.
 *
 * FINISHED GOODS ONLY. A line naming anything else is refused and pointed at
 * the returned-material door. The two paths answer to different decisions —
 * DEC-20260901-002 for finished goods, DEC-20260901-003 for raw, packing and
 * consumables — and letting one door serve both would quietly read the
 * finished-goods answer across onto material it was never given for.
 *
 * NOTHING REACHES TALLY. What Tally should receive when a finished good is
 * scrapped is undecided, exactly as it is for scrapped returned material.
 * The stock fact is recorded; the voucher waits for the answer.
 */
class DamagedFinishedGoodService
{
    public function __construct(
        private readonly QualityHoldLocationResolver $qualityHold,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * Move damaged finished goods out of a store and into quality hold.
     *
     * @param  list<array{item_id: int, quantity: string}>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    public function report(array $lines, int $fromWarehouseId, int $reportedBy, ?string $notes = null): Collection
    {
        // Resolved BEFORE anything moves. An unresolved hold refuses the whole
        // report rather than letting some lines through: half a damaged pallet
        // in the hold and half still sellable is worse than a refusal that
        // names the fix.
        $hold = $this->qualityHold->warehouseOrFail();

        if ($fromWarehouseId === $hold->id) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => 'That is the quality-hold location itself. Damaged goods are reported FROM the '
                    .'store they are standing in; goods already in the hold are Quality\'s to confirm or release.',
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A damage report has to name at least one finished good.',
            ]);
        }

        // IN ITEM ORDER, NOT THE ORDER THEY WERE TYPED — the same rule the
        // return door and the disposition path both follow, and for the same
        // reason: each move locks that material's balances, so two reports
        // sent at once in opposite orders would deadlock and InnoDB would kill
        // one of them.
        $ordered = collect($lines)->sortBy(fn (array $line): int => (int) $line['item_id'])->values();

        return DB::transaction(function () use ($ordered, $fromWarehouseId, $hold, $reportedBy, $notes): Collection {
            // The budget is read once per item and spent down, so two lines
            // naming the same finished good cannot each be told the whole
            // balance is theirs.
            $budgets = [];

            return $ordered->map(function (array $line) use (&$budgets, $fromWarehouseId, $hold, $reportedBy, $notes): array {
                $itemId = (int) $line['item_id'];
                $quantity = bcadd((string) $line['quantity'], '0', 4);

                if (bccomp($quantity, '0', 4) !== 1) {
                    throw ValidationException::withMessages([
                        'lines' => 'A damage report has to name more than zero.',
                    ]);
                }

                $item = Item::query()->withTrashed()->find($itemId);

                if ($item === null) {
                    throw ValidationException::withMessages(['lines' => 'This line names an item that does not exist.']);
                }

                // The gate this door exists to hold.
                if ($item->category !== ItemCategory::FinishedGood) {
                    throw ValidationException::withMessages([
                        'lines' => "\"{$item->name}\" is not a finished good, so it does not belong on a damaged "
                            .'finished-goods report. Material that came back from the floor damaged is recorded on the '
                            .'production return instead.',
                    ]);
                }

                if (! array_key_exists($itemId, $budgets)) {
                    $budgets[$itemId] = bcadd((string) (StockBalance::query()
                        ->where('item_id', $itemId)
                        ->where('warehouse_id', $fromWarehouseId)
                        ->lockForUpdate()
                        ->value('quantity') ?? '0'), '0', 4);
                }

                if (bccomp($quantity, $budgets[$itemId], 4) === 1) {
                    throw ValidationException::withMessages([
                        'lines' => "There are only {$budgets[$itemId]} of \"{$item->name}\" standing in that store, "
                            ."and this reports {$quantity} as damaged.",
                    ]);
                }

                $budgets[$itemId] = bcsub($budgets[$itemId], $quantity, 4);

                $this->stock->recordTransfer(
                    itemId: $itemId,
                    fromWarehouseId: $fromWarehouseId,
                    toWarehouseId: $hold->id,
                    quantity: $quantity,
                    reference: 'Damaged finished goods — reported to Quality',
                    notes: $notes,
                    createdBy: $reportedBy,
                );

                return [
                    'item_id' => $itemId,
                    'item_name' => $item->name,
                    'quantity' => $quantity,
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $hold->id,
                ];
            })->values();
        });
    }
}
