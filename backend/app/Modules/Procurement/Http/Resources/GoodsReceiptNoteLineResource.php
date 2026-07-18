<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
