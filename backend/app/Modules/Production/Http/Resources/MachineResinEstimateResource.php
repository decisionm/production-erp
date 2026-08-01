<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One machine's estimated resin remaining, per material.
 *
 * Grouped by machine rather than served flat because that is the question
 * the floor asks — "what is left on MC-03" — and a flat list would make the
 * screen do the grouping to answer it.
 */
class MachineResinEstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'work_center' => WorkCenterResource::make($this['work_center']),
            'work_center_id' => $this['work_center']->id,
            'materials' => MachineResinMaterialResource::collection($this['materials']),
        ];
    }
}
