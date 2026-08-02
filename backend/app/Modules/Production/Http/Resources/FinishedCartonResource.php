<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinishedCartonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carton_no' => $this->carton_no,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'pieces' => $this->pieces,
            'is_partial' => $this->is_partial,
            'status' => $this->status,
            'delivery_id' => $this->delivery_id,
            // The traceability spine: which batch, machine, shift and date
            // this physical box came from.
            'batch' => $this->whenLoaded('entry', fn () => [
                'shift_production_entry_id' => $this->entry->id,
                'batch_number' => $this->entry->batch_number,
                'production_date' => $this->entry->production_date?->toDateString(),
                'machine' => $this->entry->relationLoaded('workCenter') ? $this->entry->workCenter?->name : null,
                'shift' => $this->entry->relationLoaded('shift') ? $this->entry->shift?->name : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
