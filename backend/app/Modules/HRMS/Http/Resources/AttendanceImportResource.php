<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'file_name' => $this->file_name,
            'status' => $this->status->value,
            'employee_count' => $this->employee_count,
            'day_count' => $this->day_count,
            'issue_count' => $this->issue_count,
            // How many issue lines still wait for a person — the number on
            // the Apply button. Counted by the service on every read.
            'open_count' => (int) ($this->open_count ?? 0),
            'uploaded_by' => $this->when($this->relationLoaded('uploader') && $this->uploader, fn () => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
