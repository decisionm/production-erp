<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One downtime event against a batch — planned at Start or logged at
 * completion. The reason rides along (label included) so the approval and
 * floor screens need no second fetch to say "Power outage — 30 min".
 */
class ProductionDowntimeEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'downtime_reason_id' => $this->downtime_reason_id,
            'reason' => DowntimeReasonResource::make($this->whenLoaded('reason')),
            'minutes' => $this->minutes,
            'is_planned' => $this->is_planned,
            'known_before_start' => $this->known_before_start,
            'note' => $this->note,
            // The event has no from/to columns — the note carries the
            // timing text; recorded_at says when it was logged.
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
