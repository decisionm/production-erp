<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\MaterialRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a material request: what was asked for, what the store has
 * handed over so far, and what is still owed.
 *
 * THREE NUMBERS, THREE MEANINGS, none of them consumption:
 *   quantity            what the floor asked for
 *   issued_quantity     what the store has handed over — this material is
 *                       standing in Production/WIP (DEC-20260817-001), NOT
 *                       used up. What a batch consumed is calculated later
 *                       and is a different number in a different place.
 *   remaining_quantity  what the store still owes, floored at zero (a bag
 *                       is not divisible, so an issue may exceed the ask)
 *
 * The nested `item` is a deliberate summary rather than the full ItemResource:
 * the queue needs a name and a unit, and a store or floor login has no
 * business being handed the rest of the item master through a request row.
 * `display_name` joins that summary because it IS the name this screen should
 * show — the ERP's own, with Tally's underneath it — and a label the store
 * could not see was the field doing half its job (Codex, a8fe21c).
 */
class MaterialRequestLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaterialRequestLine $line */
        $line = $this->resource;

        return [
            'id' => $line->id,
            'item_id' => $line->item_id,
            'item' => $line->relationLoaded('item') && $line->item !== null ? [
                'id' => $line->item->id,
                'sku' => $line->item->sku,
                'name' => $line->item->name,
                'display_name' => $line->item->display_name,
                'uom' => $line->item->uom,
            ] : null,
            'quantity' => $line->quantity,
            // Snapshotted from the item when the request was raised (FC-03).
            'uom' => $line->uom,
            'issued_quantity' => $line->issued_quantity,
            'remaining_quantity' => $line->remainingQuantity(),
            'notes' => $line->notes,
        ];
    }
}
