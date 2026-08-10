<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachProductionStandardItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Any active item, NOT only one the suggestion list offered.
     *
     * The suggestions come from the import's diagnostic ranking, which drops
     * every catalogue name containing a packaging word ("cap", "film", "tray",
     * "pouch"…) so that the report shows bottles instead of master boxes. That
     * filter is right for a report and would be a trap here: an item whose real
     * Tally name happens to contain one of those words could never be attached
     * to anything, forever. The list is a shortcut, not the vocabulary.
     *
     * Soft-deleted items are excluded in the rule itself — `exists` counts them
     * otherwise. Whether the item is ACTIVE is checked in the service, where
     * the refusal can say which item and why.
     */
    public function rules(): array
    {
        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            // Editable identity (DEC-20260810-003): re-pointing an ALREADY
            // attached standard is a confirmed act, never a default — the
            // service refuses without this flag and its message names the
            // consequence.
            'confirm_reattach' => ['sometimes', 'boolean'],
        ];
    }
}
