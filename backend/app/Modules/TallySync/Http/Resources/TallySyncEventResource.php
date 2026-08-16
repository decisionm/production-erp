<?php

namespace App\Modules\TallySync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an entry's history, shaped for the wire. Deliberately carries
 * nothing of the entry itself (no payload, no status): the entry resource
 * already says what the voucher IS; this says what happened to it, when,
 * and who did it.
 */
class TallySyncEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'direction' => $this->direction,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'label' => $this->actor_label,
            ],
            'details' => $this->details,
            // A reconstruction from the entry's timestamps (the backfill
            // migration), never an observation — said out loud so nobody
            // reads a guess as a record.
            'backfilled' => $this->isBackfilled(),
        ];
    }
}
