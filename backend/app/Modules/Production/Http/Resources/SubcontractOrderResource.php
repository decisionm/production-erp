<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Procurement\Http\Resources\PurchaseOrderLineResource;
use App\Modules\Procurement\Http\Resources\VendorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A JOB-WORK ORDER, AND WHAT IT COSTS IS NOT THE FLOOR'S TO READ.
 *
 * This resource is served inside the production module, so its reader is a
 * production login. It handed that reader `materials_cost`, `service_cost` and
 * `total_cost` in the open — what the factory pays an outside processor, which
 * is exactly the money half of FC-06 ("Floor and sales logins never see what a
 * material cost or who supplied it").
 *
 * The gate is PurchaseOrderLineResource::showsCost, deliberately reused rather
 * than re-derived: it is the ONE predicate that decides who is served a
 * purchase rate anywhere in this app, and a second spelling of the same rule is
 * how the two come to disagree.
 *
 * Omitted, not nulled, matching every other rate gate here: a key that is
 * absent cannot be mistaken for a cost of zero.
 *
 * THE OTHER HALF OF FC-06 IS NOT SETTLED HERE. `vendor` names the supplier, and
 * FC-06 covers supplier identity too — but a production login already reaches
 * the whole vendor list through the picker this screen's own create form uses,
 * so hiding the name on this one payload would close nothing while breaking the
 * screen. That is a wider question than this resource, and it is not answered
 * by quietly changing one file.
 */
class SubcontractOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $showsCost = PurchaseOrderLineResource::showsCost($request->user());

        return [
            'id' => $this->id,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'bom_id' => $this->bom_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity_planned' => $this->quantity_planned,
            'quantity_received' => $this->quantity_received,
            ...($showsCost ? [
                'materials_cost' => $this->materials_cost,
                'service_cost' => $this->service_cost,
                'total_cost' => $this->total_cost,
            ] : []),
            'status' => $this->status->value,
            'materials' => SubcontractOrderMaterialResource::collection($this->whenLoaded('materials')),
            'materials_sent_at' => $this->materials_sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
