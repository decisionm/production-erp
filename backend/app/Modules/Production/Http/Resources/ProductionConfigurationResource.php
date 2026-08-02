<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductionConfigurationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            // The code rides beside the name because the floor calls machines
            // by code (MC-04) and the office calls them by name (Machine 4);
            // an exceptions list that carries only one of the two is unreadable
            // to whichever half of the factory is looking at it.
            'work_center' => [
                'id' => $this->work_center_id,
                'code' => $this->whenLoaded('workCenter', fn () => $this->workCenter?->code),
                'name' => $this->whenLoaded('workCenter', fn () => $this->workCenter?->name),
            ],
            'item' => ['id' => $this->item_id, 'name' => $this->whenLoaded('item', fn () => $this->item?->name), 'sku' => $this->whenLoaded('item', fn () => $this->item?->sku)],
            'mold' => $this->mold_id ? ['id' => $this->mold_id, 'name' => $this->whenLoaded('mold', fn () => $this->mold?->name)] : null,
            'colour' => $this->colour,
            'unit_weight_grams' => $this->unit_weight_grams,
            'default_cycle_time' => $this->default_cycle_time,
            'cycle_time_min' => $this->cycle_time_min,
            'cycle_time_max' => $this->cycle_time_max,
            'default_cavities' => $this->default_cavities,
            'cavities_min' => $this->cavities_min,
            'cavities_max' => $this->cavities_max,
            'permitted_cavities' => $this->permitted_cavities,
            'bom_id' => $this->bom_id,
            'status' => $this->status?->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'source' => $this->source,
            'source_reference' => $this->source_reference,
            'confirmation_status' => $this->confirmation_status,
            'notes' => $this->notes,
        ];
    }
}
