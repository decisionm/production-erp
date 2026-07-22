<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoldChangeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'production_date' => $this->production_date?->toDateString(),
            'changed_from_item' => ItemResource::make($this->whenLoaded('changedFromItem')),
            'changed_to_item' => ItemResource::make($this->whenLoaded('changedToItem')),
            'changed_to_mold' => MoldResource::make($this->whenLoaded('changedToMold')),
            'from_time' => $this->from_time?->toIso8601String(),
            'to_time' => $this->to_time?->toIso8601String(),
            'total_minutes' => $this->total_minutes,
            'status' => $this->status->value,
        ];
    }
}
