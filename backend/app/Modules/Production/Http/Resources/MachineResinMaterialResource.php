<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material's estimated remaining on one machine:
 *
 *     estimated_remaining_kg = loaded_kg − consumed_kg
 *
 * loaded_kg is every barcode scan into that machine, all time; consumed_kg
 * is the CURRENT calculated consumption of that machine's batches (a
 * correction replaces its predecessor rather than adding to it — see
 * FactoryDayBinService::machineResinEstimate).
 *
 * Every figure is a 4dp decimal STRING, the way the rest of the shift engine
 * speaks about kg — never a float, so a browser's JSON parse cannot quietly
 * restate a stock quantity.
 *
 * estimated_remaining_kg CAN BE NEGATIVE and is served that way. Consumption
 * is derived from output rather than weighed out, so a negative figure means
 * the machine ran on material nobody scanned — the one thing on this read
 * worth acting on, and the exact thing a clamp at zero would erase.
 */
class MachineResinMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item' => ItemResource::make($this['item']),
            'item_id' => $this['item']->id,
            'loaded_kg' => $this['loaded_kg'],
            'consumed_kg' => $this['consumed_kg'],
            'estimated_remaining_kg' => $this['estimated_remaining_kg'],
            // null = never loaded on this machine (the material is here
            // because it was CONSUMED on it), which is itself the answer to
            // "why is this negative".
            'last_load_at' => $this['last_load_at']?->toIso8601String(),
        ];
    }
}
