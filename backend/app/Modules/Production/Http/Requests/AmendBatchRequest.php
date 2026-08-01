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
            /*
             * "The kilograms are right as typed."
             *
             * The one field that exists only on a correction, because the
             * thing it answers only exists on a correction: a drawer that
             * opens with the previous material kg already in its boxes can
             * submit them unchanged beside counts that moved, and that is
             * the one shape the server refuses (see
             * ShiftProductionEntryService::refuseStaleMaterialLines). Sending
             * true says the supervisor looked at the refusal and means those
             * kilograms — a weighed figure that genuinely did not change
             * while a piece miscount did.
             *
             * It is NOT a general override of anything else, and it is
             * deliberately absent from CompleteBatchRequest: a first
             * completion has no previous figure to keep by mistake.
             */
            'material_kg_confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
