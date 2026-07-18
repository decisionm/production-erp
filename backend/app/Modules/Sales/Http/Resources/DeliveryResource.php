<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_order_id' => $this->sales_order_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'reference' => $this->reference,
            'delivered_date' => $this->delivered_date?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => DeliveryLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
