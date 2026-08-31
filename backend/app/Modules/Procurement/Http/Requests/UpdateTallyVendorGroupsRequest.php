<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Tally ledger groups whose parties are candidate vendors.
 *
 * An EMPTY list is valid and meaningful: it is the default, and it means the
 * review proposes no new party at all. Deciding which creditor is a supplier
 * is the owner's call — the service refuses a group that is not in the mirror
 * rather than watching nothing under a typo.
 */
class UpdateTallyVendorGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'groups' => ['present', 'array'],
            'groups.*' => ['string', 'max:255'],
        ];
    }
}
