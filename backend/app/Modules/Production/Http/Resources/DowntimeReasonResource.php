<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DowntimeReasonResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'description' => $this->description,
            'planning_type' => $this->planning_type?->value,
            'reduces_runtime' => $this->reduces_runtime,
            'requires_note' => $this->requires_note,
            'selectable_at_start' => $this->selectable_at_start,
            'is_active' => $this->is_active,
            'confirmation_status' => $this->confirmation_status,
            // The Configuration Lifecycle Contract's `can` block — stamped
            // by the controller (ManagesConfigurationRecords), never
            // re-derived here and never re-derived by the frontend. NULL
            // when nobody stamped it: undetermined, ask `show`.
            'can' => $this->resource->can ?? null,
        ];
    }
}
