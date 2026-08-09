<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;

/**
 * The LABEL read's shape: everything the scan lookup says, plus the batch's
 * completion date and shift for the printed label (DEC-20260810-001 — the
 * label ADDS completion date + shift as visible text; nothing else on it
 * changes, and the barcode payload stays the carton code only).
 *
 * A separate resource, not a change to FinishedCartonResource, because the
 * same decision freezes the public/dispatch scan response byte-for-byte:
 * lookup keeps serving the parent shape untouched, and only the
 * generate/reprint label endpoints serve this one. No cost, no rate, no lot
 * identity here either — those exist solely on the internal trace tier.
 */
class FinishedCartonLabelResource extends FinishedCartonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'completion' => $this->whenLoaded('entry', function () {
                $completedAt = $this->entry->batchCompletedAt();

                return [
                    // The factory's calendar day (IST) — a UTC date would
                    // misfile every completion before 05:30 local.
                    'completed_on' => $completedAt
                        ?->setTimezone(config('tally-sync.factory_timezone'))
                        ->toDateString(),
                    'shift' => $this->entry->relationLoaded('shift') ? $this->entry->shift?->name : null,
                ];
            }),
        ];
    }
}
