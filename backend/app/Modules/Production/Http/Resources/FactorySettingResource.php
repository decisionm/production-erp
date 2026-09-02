<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FactorySettingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->value,
            'typed_value' => $this->typedValue(),
            'data_type' => $this->data_type,
            'scope' => $this->scope,
            'label' => $this->label,
            'description' => $this->description,
            'confirmation_status' => $this->confirmation_status,
            // Whether any screen or rule reads this value. The UI marks the
            // rest "Not in use" instead of offering a control that does nothing.
            'applied' => $this->isReadBySoftware(),
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'change_reason' => $this->change_reason,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy?->name),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
