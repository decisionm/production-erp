<?php

namespace App\Modules\Maintenance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // parts_cost is Σ(quantity × unit_cost) and quantity is served per
        // part, so on a one-part order parts_cost / quantity IS the purchase
        // rate. Same gate as MaintenanceWorkOrderPartResource (see
        // MaterialLotResource's class note, FC-06): finance eyes only, keys
        // absent not null. labor_cost is not a purchase rate and stays.
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'asset' => AssetResource::make($this->whenLoaded('asset')),
            'maintenance_schedule_id' => $this->maintenance_schedule_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'description' => $this->description,
            'reported_date' => $this->reported_date?->toDateString(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'assignee' => $this->when(
                $this->relationLoaded('assignee') && $this->assignee,
                fn () => ['id' => $this->assignee->id, 'name' => $this->assignee->name],
            ),
            'labor_cost' => $this->labor_cost,
            ...($showsCost ? [
                'parts_cost' => $this->parts_cost,
                'total_cost' => $this->total_cost,
            ] : []),
            'parts' => MaintenanceWorkOrderPartResource::collection($this->whenLoaded('parts')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
