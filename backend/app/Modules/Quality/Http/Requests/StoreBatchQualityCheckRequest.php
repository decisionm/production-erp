<?php

namespace App\Modules\Quality\Http\Requests;

use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The quality desk's count for one completed batch: how many bottles were
 * reviewed, how many passed, how many were rejected.
 *
 * THE THREE MUST RECONCILE. reviewed = ok + rejected is checked here rather
 * than being derived, because deriving it would accept every typo silently:
 * a desk that reviewed 500, passed 480 and typed 15 rejected has miscounted
 * something, and the five bottles it cannot account for are exactly what this
 * gate exists to notice. Making the operator restate the total is the whole
 * value of asking for three numbers instead of two.
 */
class StoreBatchQualityCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the route's module:quality guard (POST ⇒
        // quality.manage), the same mechanism every other Quality endpoint
        // uses. Nothing entry-specific belongs here — who may check WHICH
        // batch is a four-eyes question, and that is a transition rule the
        // service owns alongside the accountant's.
        return true;
    }

    public function rules(): array
    {
        return [
            // Whole bottles. 'integer' rather than 'numeric' on purpose:
            // half a bottle is not a thing anyone can review, and a decimal
            // arriving here means a client is sending kilograms — the mistake
            // this stage most needs to refuse, since the kg figure is derived
            // from these counts downstream.
            'reviewed_nos' => ['required', 'integer', 'min:0'],
            'ok_nos' => ['required', 'integer', 'min:0'],
            'rejected_nos' => ['required', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $reviewed = (int) $this->input('reviewed_nos');
            $ok = (int) $this->input('ok_nos');
            $rejected = (int) $this->input('rejected_nos');

            if ($reviewed !== $ok + $rejected) {
                $validator->errors()->add(
                    'reviewed_nos',
                    sprintf(
                        'The reviewed count must equal ok plus rejected (%d + %d = %d, not %d).',
                        $ok,
                        $rejected,
                        $ok + $rejected,
                        $reviewed,
                    ),
                );
            }

            // A batch cannot reject more than it made. Compared against the
            // entry's CURRENT quantity_produced, which is the gross figure at
            // this point precisely because a second check is refused — if
            // re-checking were ever allowed this comparison would silently
            // start measuring against an already-netted total.
            $entry = $this->route('shift_production_entry');

            if (! $entry instanceof ShiftProductionEntry || $entry->quantity_produced === null) {
                return;
            }

            if (bccomp((string) $rejected, (string) $entry->quantity_produced, 4) === 1) {
                $validator->errors()->add(
                    'rejected_nos',
                    sprintf(
                        'This batch produced %s — it cannot have %d rejected.',
                        rtrim(rtrim((string) $entry->quantity_produced, '0'), '.'),
                        $rejected,
                    ),
                );
            }
        });
    }
}
