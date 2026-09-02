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
 * THIS RULE STILL DOES NOT CONSULT THE ITEM'S CATEGORY — that is handled
 * elsewhere, not absent. Q59(a)/(d) are answered: DEC-20260902-023 refuses a
 * finished good and demands a reason for an unclassified item on a
 * requisition and on an ERP-entered purchase order (create and, for a NEW or
 * CHANGED line, amend). That refusal is
 * `App\Modules\Procurement\Support\PurchaseLineEligibility`, called from
 * each request's `withValidator()` hook alongside this rule — a sibling
 * check, not a change to this one. `ItemCategory::purchasable()` is still
 * not called HERE; the eligibility check reads categories through
 * `ItemService::categoriesFor()` instead (cross-module reads go through the
 * other module's Service, not its Eloquent model).
 *
 * `InactiveMasterGuardTest`'s negative controls were rewritten to say so —
 * see its class docblock — and `PurchaseLineEligibilityTest` pins the new
 * refusal in full.
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
