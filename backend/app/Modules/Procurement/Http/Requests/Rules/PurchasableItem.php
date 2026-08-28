<?php

namespace App\Modules\Procurement\Http\Requests\Rules;

use App\Modules\Inventory\Models\Item;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * MAY THIS ITEM BE PUT ON A NEW PURCHASE-ORDER LINE?
 *
 * ONE REFUSAL: the item is OUT OF SERVICE — archived or trashed. Nothing else.
 *
 * An item the factory has taken out of service may not be given NEW work.
 * `is_active` is the state archive uses — it CLEARS the flag rather than
 * trashing the row (ItemResource), because the Tally masters pull restores
 * trashed items. A soft-deleted row is the second half of the same state and
 * has to be looked for deliberately: `exists:items,id` queries the table and
 * applies no model scope, so a trashed id passes it, and a plain find()
 * would return null and fall through the missing-id branch below. Hence
 * withTrashed(). This is the same rule WS-B already applies to the vendor on
 * this request and to the warehouse on a goods receipt; the item was simply
 * never given it, so `exists:items,id` accepted an out-of-service row.
 *
 * THE ITEM'S CATEGORY IS NOT CONSULTED, DELIBERATELY. `ItemCategory` carries
 * a `purchasable()` helper that would refuse a finished good here, and this
 * rule does not call it. Q59 asks which categories each document may use —
 * (a) is a purchase order raw+packing only, or may `Other` be bought too;
 * (d) what happens to an item nobody has classified — and it says plainly
 * that what must not proceed is "making a document refuse an item" until
 * those are settled. So a finished good, a consumable, and an unclassified
 * item are all accepted on a purchase-order line: the ERP records what the
 * factory does and does not narrow it on a rule nobody has confirmed.
 *
 * That is an OWNER decision to reverse, not a cleanup. If Q59(a) comes back
 * saying a purchase order is for raw and packing material only, the refusal
 * belongs here — and `InactiveMasterGuardTest`'s negative controls are the
 * tests that must be rewritten to say so, deliberately.
 *
 * Not applied to the FILTER bar, a report, or any read: a purchase order
 * already raised against an item since archived must stay readable.
 */
class PurchasableItem implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $item = Item::query()->withTrashed()->find($value);

        // Genuinely absent. `exists:items,id` reports a missing id; this rule
        // does not double-report it.
        if ($item === null) {
            return;
        }

        if ($item->trashed() || ! $item->is_active) {
            $fail("{$item->name} is archived and cannot be put on a new purchase order.");
        }
    }
}
