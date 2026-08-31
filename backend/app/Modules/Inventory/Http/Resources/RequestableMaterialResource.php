<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE material the floor may ask the store for.
 *
 * Deliberately NOT ItemResource. This is not "an item" — it is an item in a
 * particular ROLE, and the shape says so: the picker needs a label, a unit,
 * and whether naming a machine is meaningful for it. Nothing else about the
 * item belongs on a request screen.
 *
 * @mixin Item
 */
class RequestableMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'uom' => $this->uom,
            // FC-01 / Q50, computed on the SERVER by the same predicate the
            // write-side guard uses (MaterialRequestService::
            // guardCommonInputNamesNoMachine -> Item::hasKgUom). The browser
            // used to derive this by fetching a second, day-bin-named list and
            // testing membership; one read now answers it, and the answer is
            // the refusing code's own.
            //
            // This is the MACHINE question, and it is NOT the eligibility
            // question. A material is requestable because it is configured as
            // a production input; whether it may name a machine is a separate
            // owner-backed refusal (DEC-20260807-006). Conflating them would
            // quietly re-open Q54(a).
            'machine_applies' => ! $this->hasKgUom(),
            // WHAT IS ALREADY STANDING ON THE FLOOR for this material
            // (DEC-20260831-001), decorated by MaterialRequestService so the
            // picker can show total required / already in production /
            // balance to request without a second round trip per line.
            //
            // `available_in_production` is the USABLE figure — a negative
            // balance and a unit that disagrees with the handover's both
            // report zero, because neither may be subtracted from what the
            // floor needs. `production_unit_matches` is false only in the
            // second case, so the screen can say the quantity is there and
            // still not net it.
            'available_in_production' => $this->availableInProduction ?? '0.0000',
            // WHAT IS ACTUALLY STANDING THERE, netted or not — negative
            // included. A negative balance and a unit the master no longer
            // agrees with are both excluded from the netting and both stay
            // VISIBLE (DEC-20260831-005): publishing only the usable figure
            // shows 0 for each, which reads as an empty floor.
            'standing_in_production' => $this->standingInProduction ?? '0.0000',
            'production_unit_matches' => $this->productionUnitMatches ?? true,
        ];
    }
}
