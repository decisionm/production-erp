<?php

namespace App\Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeasuringInstrumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location,
            'calibration_frequency_days' => $this->calibration_frequency_days,
            'last_calibrated_date' => $this->last_calibrated_date?->toDateString(),
            'next_calibration_due' => $this->next_calibration_due?->toDateString(),
            'status' => $this->status->value,
            'calibration_records' => CalibrationRecordResource::collection($this->whenLoaded('calibrationRecords')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
