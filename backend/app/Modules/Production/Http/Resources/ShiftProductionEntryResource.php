<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftProductionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'production_date' => $this->production_date?->toDateString(),
            'quantity_produced' => $this->quantity_produced,
            'quantity_scrap' => $this->quantity_scrap,
            'scrap_reason' => ScrapReasonResource::make($this->whenLoaded('scrapReason')),
            'operator' => EmployeeResource::make($this->whenLoaded('operator')),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
