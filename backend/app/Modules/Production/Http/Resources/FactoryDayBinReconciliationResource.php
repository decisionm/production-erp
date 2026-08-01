<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One raw material's day-bin reconciliation row for a chosen date: what was
 * in the bin at 00:00, what went in, what came out, and what should
 * therefore be left. Wraps the array rows
 * FactoryDayBinService::reconciliation() builds — see that docblock for the
 * arithmetic and, importantly, for why expected_closing_kg is NOT a check.
 *
 * Every figure is a 4dp decimal STRING, the same way the rest of the shift
 * engine speaks about kg — never a float, so a browser's JSON parse cannot
 * quietly restate a stock quantity.
 *
 * There is deliberately no actual/physical field here. The real check is a
 * count a person takes and types in; the frontend holds that number and
 * does the subtraction client-side. The server offers the expected side and
 * takes no view on whether the material is there.
 */
class FactoryDayBinReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item' => ItemResource::make($this['item']),
            'item_id' => $this['item']->id,
            'opening_kg' => $this['opening_kg'],
            'loaded_kg' => $this['loaded_kg'],
            'consumed_kg' => $this['consumed_kg'],
            'expected_closing_kg' => $this['expected_closing_kg'],
            // Normally '0.0000'. Non-zero means material moved through the
            // bin on this date by neither route (a receipt booked straight
            // into the bin, or a transfer back out to the store), and the
            // identity the page compares against becomes
            // live = expected_closing + other_movements.
            'other_movements_kg' => $this['other_movements_kg'],
        ];
    }
}
