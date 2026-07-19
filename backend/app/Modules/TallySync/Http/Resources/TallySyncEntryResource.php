<?php

namespace App\Modules\TallySync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TallySyncEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'syncable_type' => class_basename($this->syncable_type),
            'syncable_id' => $this->syncable_id,
            'tally_voucher_type' => $this->tally_voucher_type,
            'payload' => $this->payload,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'error_message' => $this->error_message,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
