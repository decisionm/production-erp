<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryStructureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->when(
                $this->relationLoaded('employee') && $this->employee,
                fn () => ['id' => $this->employee->id, 'name' => $this->employee->name],
            ),
            'effective_from' => $this->effective_from?->toDateString(),
            'lines' => SalaryStructureLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
