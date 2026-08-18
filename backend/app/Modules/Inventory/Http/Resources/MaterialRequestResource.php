<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\MaterialRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material request as the store's queue, the show endpoint and every
 * lifecycle action return it.
 *
 *   request_number   "MR-{id}" — what the floor and the store quote
 *   status           draft | submitted | partially_issued | issued |
 *                    cancelled. `issued` means HANDED OVER, never CONSUMED.
 *   work_center      null on a common-input request, by rule (FC-01 /
 *                    DEC-20260807-006) — a null here is the answer, not a
 *                    gap in the data, and a screen must say so in words.
 *   can              {submit, cancel, issue} as MaterialRequestService::
 *                    abilities computed them — the SAME predicate the
 *                    actions enforce, so no screen re-derives the state
 *                    machine.
 *
 * FC-06: no rate, no amount, no vendor, anywhere in this shape or in the
 * line shape below it. A material request is about kilograms and cartons,
 * not about money, so it needs no finance standing to read and grants none.
 */
class MaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaterialRequest $materialRequest */
        $materialRequest = $this->resource;

        return [
            'id' => $materialRequest->id,
            'request_number' => $materialRequest->documentNumber(),
            'status' => $materialRequest->status->value,

            'requested_by' => $materialRequest->requested_by,
            'requested_by_name' => $materialRequest->relationLoaded('requestedBy')
                ? $materialRequest->requestedBy?->name
                : null,
            'requested_at' => $materialRequest->requested_at?->toIso8601String(),

            'shift_id' => $materialRequest->shift_id,
            'shift_name' => $materialRequest->relationLoaded('shift') ? $materialRequest->shift?->name : null,

            // NULL for a common-input request — see the class docblock.
            'work_center_id' => $materialRequest->work_center_id,
            'work_center_code' => $materialRequest->relationLoaded('workCenter') ? $materialRequest->workCenter?->code : null,
            'work_center_name' => $materialRequest->relationLoaded('workCenter') ? $materialRequest->workCenter?->name : null,

            'notes' => $materialRequest->notes,

            'submitted_at' => $materialRequest->submitted_at?->toIso8601String(),
            'cancelled_by' => $materialRequest->cancelled_by,
            'cancelled_by_name' => $materialRequest->relationLoaded('cancelledBy') ? $materialRequest->cancelledBy?->name : null,
            'cancelled_at' => $materialRequest->cancelled_at?->toIso8601String(),
            'cancelled_reason' => $materialRequest->cancelled_reason,

            'lines' => MaterialRequestLineResource::collection($this->whenLoaded('lines')),
            'can' => $materialRequest->can,

            'created_at' => $materialRequest->created_at?->toIso8601String(),
        ];
    }
}
