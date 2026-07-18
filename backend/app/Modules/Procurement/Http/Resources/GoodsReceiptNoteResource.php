<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'reference' => $this->reference,
            'received_date' => $this->received_date?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => GoodsReceiptNoteLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
