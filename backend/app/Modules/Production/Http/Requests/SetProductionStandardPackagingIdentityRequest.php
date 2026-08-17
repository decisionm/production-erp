<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Set or clear ONE packaging variant's own Tally identity — and nothing
 * else (DEC-20260810-003; Phase 5 fix P1-a).
 *
 * The full update (SaveProductionStandardPackagingRequest) carries the mode
 * and the counts, and re-deriving a box count from unchanged inner counts
 * is how a link once turned the sheet's 520 into 525 on a row the importer
 * had deliberately left inconsistent. This request carries `item_id` alone:
 * anything else in the payload is not validated and never reaches the
 * service.
 *
 * `item_id` must be PRESENT — null is a real answer ("no identity of its
 * own, use the product's"), an absent key is not an answer at all. A
 * non-null item meets the same refusals the full update applies to an
 * identity, plus one this route can afford to state outright because it is
 * only ever asked by the review surface, whose candidates are Tally-pulled
 * rows: the item must carry a Tally GUID. A soft-deleted, inactive,
 * GUID-less or local-fixture item is refused with the reason named — none
 * of them is something a Tally voucher can be posted under.
 */
class SetProductionStandardPackagingIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-group middleware ('module:production') already requires
        // production.manage for a non-GET, same as every write neighbour.
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['present', 'nullable', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('item_id') || $this->input('item_id') === null) {
                return;
            }

            $item = Item::query()->find($this->input('item_id'));

            if ($item === null) {
                return;
            }

            if (! $item->is_active) {
                $validator->errors()->add(
                    'item_id',
                    "\"{$item->name}\" is not an active item — production cannot post finished goods under it.",
                );
            }

            if ($item->isLocalFixture()) {
                $validator->errors()->add(
                    'item_id',
                    "\"{$item->name}\" is a local-only fixture; Tally cannot accept it as a posted identity.",
                );
            } elseif ($item->tally_stock_item_guid === null) {
                $validator->errors()->add(
                    'item_id',
                    "\"{$item->name}\" carries no Tally GUID — it has never been pulled from Tally, so a voucher cannot name it. Pull the masters first, then link.",
                );
            }
        });
    }
}
