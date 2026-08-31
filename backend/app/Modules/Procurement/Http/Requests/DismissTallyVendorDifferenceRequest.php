<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Services\TallyVendorReviewService;
use App\Modules\TallySync\Models\TallyVendorReviewDismissal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One difference set aside — a named field, or `*` for "this ledger is not a
 * vendor".
 *
 * The value dismissed is not posted either: the service records what Tally
 * carries at the moment of the dismissal, so the row returns if Tally later
 * says something different. A dismissal is a judgement about a fact, not a
 * permanent blindfold.
 */
class DismissTallyVendorDifferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tally_ledger_guid' => ['required', 'string', 'max:255'],
            'field' => ['required', 'string', Rule::in([
                TallyVendorReviewDismissal::FIELD_ALL,
                ...TallyVendorReviewService::REVIEWABLE_FIELDS,
            ])],
        ];
    }
}
