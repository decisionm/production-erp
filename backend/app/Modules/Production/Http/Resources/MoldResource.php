<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'cavity_count' => $this->cavity_count,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            // The Configuration Lifecycle Contract's `can` block — stamped
            // by the controller (ManagesConfigurationRecords), never
            // re-derived here and never re-derived by the frontend. NULL
            // when nobody stamped it: undetermined, ask `show`.
            'can' => $this->resource->can ?? null,
        ];
    }
}
