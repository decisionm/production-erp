<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
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
            'batch_status' => $this->batch_status->value,
            'batch_number' => $this->batch_number,
            'quantity_produced' => $this->quantity_produced,
            'quantity_produced_kg' => $this->quantity_produced_kg,
            'quantity_scrap' => $this->quantity_scrap,
            'quantity_rejection_kg' => $this->quantity_rejection_kg,
            'scrap_reason' => ScrapReasonResource::make($this->whenLoaded('scrapReason')),
            'nos_per_tray' => $this->nos_per_tray,
            'no_of_trays' => $this->no_of_trays,
            'nos_per_box' => $this->nos_per_box,
            'no_of_box' => $this->no_of_box,
            'material_consumptions' => ShiftMaterialConsumptionResource::collection($this->whenLoaded('materialConsumptions')),
            'scraps' => ShiftScrapResource::collection($this->whenLoaded('scraps')),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'approved_by' => UserResource::make($this->whenLoaded('approvedBy')),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'operator' => EmployeeResource::make($this->whenLoaded('operator')),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
