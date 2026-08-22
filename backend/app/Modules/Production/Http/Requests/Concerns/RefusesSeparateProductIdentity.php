<?php

namespace App\Modules\Production\Http\Requests\Concerns;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductVariantService;
use Illuminate\Validation\Validator;

/**
 * DEC-20260821-001 on the two configuration writers, shared by
 * SaveProductionStandardPackagingRequest (POST/PUT, item_id included) and
 * SetProductionStandardPackagingIdentityRequest (PATCH .../identity) so
 * neither is a way around the other. Both routes reach the same service
 * methods, and a rule stated on only one of them is a rule with a door
 * beside it.
 *
 * THE AUTHORITY THAT WENT AWAY. DEC-20260810-003 let each packaging row
 * hold "a distinct value on each when [Tally] does". DEC-20260821-001
 * supersedes it: where Tally carries separate stock items for a product's
 * pouch and tray packings, the ERP holds two separate finished-product
 * masters. So the authority to make a NEW distinct assignment is gone.
 *
 * WHAT IS STILL ACCEPTED, and why each one has to be:
 *
 *  - `null` — clearing an identity back to the product's. This is the one
 *    edit that migrates a legacy row TOWARD the new rule; making the
 *    endpoint wholly read-only would delete it.
 *  - the product's OWN item — one product, one Tally item, stated twice.
 *    Not a conflict and never was.
 *  - the value an existing row ALREADY carries. A legacy row written under
 *    the old authority is history, and history is not rewritten here; but
 *    it must stay maintainable, so a counts-only or default-flag edit that
 *    re-sends the identity it already has is let through. Changing it to
 *    ANOTHER distinct item is a new distinct assignment and is refused.
 *  - anything at all on an UNATTACHED standard (item_id null). It has no
 *    product to conflict with — see identityConflictsWithProduct().
 *
 * Nothing here clears, rewrites or migrates a stored row. The refusal is
 * on the incoming value only.
 */
trait RefusesSeparateProductIdentity
{
    /**
     * Refuse an incoming `item_id` that would newly point this packing at a
     * different product from the standard's own.
     *
     * @param  ProductionStandardPackaging|null  $existing  the row being edited; null when one is being added
     */
    protected function refuseSeparateProductIdentity(
        Validator $validator,
        ?ProductionStandard $standard,
        ?ProductionStandardPackaging $existing,
        mixed $incoming,
    ): void {
        if ($incoming === null || $standard === null) {
            return;
        }

        $incomingId = (int) $incoming;
        $productId = $standard->item_id === null ? null : (int) $standard->item_id;

        if (! ProductVariantService::identityConflictsWithProduct($incomingId, $productId)) {
            return;
        }

        // Unchanged on an existing legacy row: the row keeps what it has.
        if ($existing !== null && $existing->item_id !== null && (int) $existing->item_id === $incomingId) {
            return;
        }

        $product = $standard->relationLoaded('item') ? $standard->item : Item::query()->find($productId);
        $candidate = Item::query()->find($incomingId);

        $productName = trim((string) ($product?->name ?? '')) ?: "item #{$productId}";
        $candidateName = trim((string) ($candidate?->name ?? '')) ?: "item #{$incomingId}";

        $validator->errors()->add(
            'item_id',
            "\"{$candidateName}\" is a different Tally stock item from this standard's product, \"{$productName}\". "
            .ProductVariantService::SEPARATE_PRODUCT_REASON.' '
            .ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION
            .' Leaving the identity blank (the product\'s own item) is still accepted.',
        );
    }
}
