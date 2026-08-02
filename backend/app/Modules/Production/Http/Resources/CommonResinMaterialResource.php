<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material's estimated remaining in the COMMON RESIN INPUT:
 *
 *     estimated_remaining_kg = loaded_kg − consumed_kg
 *
 * loaded_kg is every load of that material into the common input, all time;
 * consumed_kg is the CURRENT calculated consumption of that material across
 * ALL machines (a correction replaces its predecessor rather than adding to
 * it — see FactoryDayBinService::commonResinEstimate).
 *
 * THERE IS NO MACHINE FIELD, and its absence is the point. The owner's
 * correction (2-Aug): the factory has one common resin input point, a bag is
 * never assigned or scanned to a machine, and a per-machine balance was a
 * number with no physical referent. This resource replaced the machine-keyed
 * pair (MachineResinEstimateResource + MachineResinMaterialResource) rather
 * than nulling their machine field, so nothing downstream can keep rendering
 * a dimension that no longer exists.
 *
 * Every figure is a 4dp decimal STRING, the way the rest of the shift engine
 * speaks about kg — never a float, so a browser's JSON parse cannot quietly
 * restate a stock quantity.
 *
 * estimated_remaining_kg CAN BE NEGATIVE and is served that way. Consumption
 * is derived from output rather than weighed out, so a negative figure means
 * material was run that nobody recorded loading — the one thing on this read
 * worth acting on, and the exact thing a clamp at zero would erase.
 *
 * IT IS AN ESTIMATE AND IT LAGS THE FLOOR. Consumption is booked at batch
 * completion, so material an in-flight batch has already melted is not yet
 * subtracted. Consumers must say so where they print it, the way the load
 * gate's own refusal does.
 */
class CommonResinMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item' => ItemResource::make($this['item']),
            'item_id' => $this['item']->id,
            'loaded_kg' => $this['loaded_kg'],
            'consumed_kg' => $this['consumed_kg'],
            'estimated_remaining_kg' => $this['estimated_remaining_kg'],
            'last_load_at' => $this['last_load_at']?->toIso8601String(),
        ];
    }
}
