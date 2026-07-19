<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\CircularBomException;

/**
 * "Basic MRP" per DEVELOPMENT-PLAN.md Phase 2 — a single-item, on-demand
 * net-requirements explosion, not a persisted, time-phased MRP run (that's
 * Phase 4/5 territory). Assemblies that have their own active BOM are
 * exploded into their components rather than purchased directly; only
 * leaf items (no BOM of their own — raw materials / purchased parts) are
 * reported, since those are what actually need to be procured.
 */
class MrpService
{
    public function __construct(
        private readonly BomService $boms,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * @return array<int, array{item_id: int, sku: ?string, name: ?string, gross_required: string, on_hand: string, net_required: string}>
     */
    public function netRequirements(int $itemId, string $quantity): array
    {
        $grossRequirements = [];
        $this->explode($itemId, $quantity, $grossRequirements, []);

        $items = Item::query()->whereIn('id', array_keys($grossRequirements))->get()->keyBy('id');

        return collect($grossRequirements)
            ->map(function (string $grossRequired, int $componentItemId) use ($items) {
                $onHand = $this->stock->totalOnHand($componentItemId);
                $netRequired = bccomp($grossRequired, $onHand, 4) > 0
                    ? bcsub($grossRequired, $onHand, 4)
                    : '0.0000';

                return [
                    'item_id' => $componentItemId,
                    'sku' => $items->get($componentItemId)?->sku,
                    'name' => $items->get($componentItemId)?->name,
                    'gross_required' => $grossRequired,
                    'on_hand' => $onHand,
                    'net_required' => $netRequired,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $grossRequirements  component_item_id => accumulated quantity, by reference
     * @param  array<int, int>  $visited  the chain of item IDs on the current explosion path, for cycle detection
     */
    private function explode(int $itemId, string $quantity, array &$grossRequirements, array $visited): void
    {
        if (in_array($itemId, $visited, true)) {
            throw CircularBomException::forItem($itemId);
        }
        $visited[] = $itemId;

        $bom = $this->boms->activeFor($itemId);

        if (! $bom) {
            $grossRequirements[$itemId] = bcadd($grossRequirements[$itemId] ?? '0', $quantity, 4);

            return;
        }

        foreach ($bom->lines as $line) {
            $this->explode(
                $line->component_item_id,
                bcmul($line->quantity_per, $quantity, 4),
                $grossRequirements,
                $visited,
            );
        }
    }
}
