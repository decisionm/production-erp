<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutingOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'sequence' => $this->sequence,
            'name' => $this->name,
            'standard_time_minutes' => $this->standard_time_minutes,
        ];
    }
}
