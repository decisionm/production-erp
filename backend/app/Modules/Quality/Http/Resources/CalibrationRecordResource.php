<?php

namespace App\Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalibrationRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'calibrated_date' => $this->calibrated_date?->toDateString(),
            'certificate_number' => $this->certificate_number,
            'result' => $this->result->value,
            'performed_by' => $this->performed_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
