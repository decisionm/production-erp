<?php

namespace App\Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'non_conformance_report_id' => $this->non_conformance_report_id,
            'title' => $this->title,
            'problem_statement' => $this->problem_statement,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'preventive_action' => $this->preventive_action,
            'owner' => $this->when(
                $this->relationLoaded('ownerEmployee') && $this->ownerEmployee,
                fn () => ['id' => $this->ownerEmployee->id, 'name' => $this->ownerEmployee->name],
            ),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'verified_effective' => $this->verified_effective,
            'closed_date' => $this->closed_date?->toDateString(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
