<?php

namespace App\Modules\Production\Http\Requests;

/**
 * A correction to a completed batch is a COMPLETION, retyped: the same
 * counts, the same packing lines, the same consumption, the same closing
 * weights. So this request is CompleteBatchRequest — every rule, every
 * cross-line check (the packing lines must add up to the piece count, a
 * counted figure that differs from the pack sizes needs a reason) — plus one
 * optional field of its own.
 *
 * Inheriting rather than copying is the point: the two payloads must never
 * drift apart, because a rule that holds at completion and not at amendment
 * is a rule the floor can get round by completing wrong on purpose.
 *
 * THE REASON IS OPTIONAL, and that is deliberate. The owner's rule is plainly
 * that the floor may edit its own entry until quality starts — asking a
 * supervisor to justify fixing their own typo, on their own batch, before
 * anyone else has seen it, is friction the rule does not ask for. What IS
 * mandatory is the trail: who amended, when, and what the previous figure was
 * are recorded whether or not a reason is given (see
 * ShiftProductionEntryService::amendCompletion). Quality's return, by
 * contrast, is one desk sending work back to another and its reason is
 * required — see ReturnBatchToProductionRequest.
 */
class AmendBatchRequest extends CompleteBatchRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'amendment_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
