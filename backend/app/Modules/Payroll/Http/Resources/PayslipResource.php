<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'employee' => $this->when(
                $this->relationLoaded('employee') && $this->employee,
                fn () => ['id' => $this->employee->id, 'name' => $this->employee->name],
            ),
            'gross_earnings' => $this->gross_earnings,
            'total_deductions' => $this->total_deductions,
            'net_pay' => $this->net_pay,
            'lines' => PayslipLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
