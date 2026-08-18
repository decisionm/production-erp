<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\MaterialLotResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // unit_cost IS the lot's receipt_rate_per_kg (FC-06, Owner/Accounts
        // only): same gate, same omit-not-null rule as the nested
        // MaterialLotResource — see its class note. Served open, the parent
        // line printed the very number its child lot was hiding.
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            ...($showsCost ? ['unit_cost' => $this->unit_cost] : []),
            'material_lots' => MaterialLotResource::collection($this->whenLoaded('materialLots')),
        ];
    }
}
