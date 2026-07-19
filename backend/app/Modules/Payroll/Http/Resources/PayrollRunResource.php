<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'status' => $this->status->value,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payslips' => PayslipResource::collection($this->whenLoaded('payslips')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
