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
        ];
    }
}
