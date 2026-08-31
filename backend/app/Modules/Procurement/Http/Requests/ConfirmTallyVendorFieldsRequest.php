<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Services\TallyVendorReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which differences a person is confirming onto which vendor.
 *
 * The VALUES are deliberately absent from this request. They are re-read from
 * the ledger mirror when the confirm runs, so what gets written is what Tally
 * says now rather than what the screen said when it was rendered — and no
 * client can post a value that Tally never carried under the banner of a
 * Tally-sourced update.
 */
class ConfirmTallyVendorFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tally_ledger_guid' => ['required', 'string', 'max:255'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => [Rule::in(TallyVendorReviewService::REVIEWABLE_FIELDS)],
        ];
    }
}
