<?php

namespace App\Modules\Inventory\Http\Requests\Concerns;

use App\Modules\Inventory\Models\Item;
use Illuminate\Validation\Validator;

/**
 * THE PACK-VARIANT LINK IS ONE LEVEL DEEP, and this is the one place that
 * says so.
 *
 * DEC-20260821-001 made a pack variant that carries its own Tally stock item
 * a SEPARATE ERP master related to a base product — a flat pair, base and
 * variant, not a tree. `items.variant_of_item_id` is a plain self-referencing
 * FK and a database cannot express "one level"; without a rule, three writes
 * that are each individually reasonable produce a chain or a loop:
 *
 *   1. an item pointed at ITSELF — a group of one that reads as a variant;
 *   2. an item pointed at ANOTHER VARIANT — B -> A -> base, so "the base of
 *      this product" needs a walk, and a cycle needs a depth bound;
 *   3. the same thing FROM THE OTHER SIDE, which rules 1 and 2 both miss:
 *      A already has variant B, and now A is pointed at C. Nothing about
 *      that write looks wrong on its own, and it leaves B -> A -> C.
 *
 * All three are refused, and none of them is a new refusal on existing data:
 * the column ships nullable with no backfill, so on the day this lands every
 * item is a base and every base is legal.
 *
 * WHAT IS NOT DECIDED HERE. Whether two masters ARE variants of one product
 * is a factory fact, and nothing infers it — no name matching, no SKU
 * parsing, no packing-standard comparison. A person links them. Q33 leaves
 * one such identity unevidenced and it stays NULL (AGENTS.md: never invent a
 * factory value).
 */
trait ValidatesVariantLink
{
    /**
     * @param  Item|null  $item  the item being edited, or null on create
     */
    protected function validateVariantLink(Validator $validator, ?Item $item = null): void
    {
        if ($validator->errors()->has('variant_of_item_id')) {
            // The `exists` rule already failed — a second sentence about the
            // same mistake helps nobody.
            return;
        }

        $this->validateVariantLabelNeedsALink($validator, $item);

        if (! $this->has('variant_of_item_id')) {
            return;
        }

        $targetId = $this->input('variant_of_item_id');

        if ($targetId === null || $targetId === '') {
            // Clearing the link is always allowed: it makes this item a base,
            // which is the state every item starts in. The LABEL does not
            // survive it — see prepareVariantLabelForUnlink().
            return;
        }

        $targetId = (int) $targetId;

        if ($item !== null && $targetId === (int) $item->getKey()) {
            $validator->errors()->add(
                'variant_of_item_id',
                'An item cannot be a pack variant of itself.',
            );

            return;
        }

        $target = Item::find($targetId);

        if ($target === null) {
            return;
        }

        if ($target->variant_of_item_id !== null) {
            $validator->errors()->add(
                'variant_of_item_id',
                "\"{$target->displayName()}\" is itself a pack variant. Point this item at the BASE product they both "
                    .'vary from, not at another variant.',
            );

            return;
        }

        /*
         * ARCHIVED VARIANTS COUNT. `items` soft-deletes, so the plain
         * relation would not see one — and an archived variant is still a
         * physical row pointing at this item, exactly as ItemService's own
         * dependency check for this column says (`->includeTrashed()`, "an
         * archived variant is still a physical row"). Without withTrashed
         * the two halves of the same rule disagree and the disagreement is
         * reachable: archive variant B (pointing at A), repoint A at base C
         * while the guard sees no variants, then let the Tally masters pull
         * restore B — ItemService::upsertFromTally() looks items up
         * withTrashed() and calls restore() — and B -> A -> C is live. That
         * is the two-level chain this trait and Item::variantRootId()'s
         * "One level, no walk" both promise cannot exist.
         */
        if ($item !== null && $item->variants()->withTrashed()->exists()) {
            $count = $item->variants()->withTrashed()->count();
            $live = $item->variants()->count();

            $validator->errors()->add(
                'variant_of_item_id',
                "\"{$item->displayName()}\" is the base product for {$count} pack "
                    .($count === 1 ? 'variant' : 'variants')
                    .($count > $live ? ' (archived ones included — an archived variant still points here)' : '')
                    .', so it cannot become a variant itself. Repoint those first.',
            );
        }
    }

    /**
     * A LABEL WITHOUT A LINK IS A LABEL ABOUT NOTHING.
     *
     * `variant_label` names WHICH pack variant an item is — "840/box pouch"
     * only means something once the item points at the base product it varies
     * from. Offered alone it is refused rather than stored, because a stored
     * one is invisible (the list renders labels for linked variants only) and
     * comes back to life the day somebody links the item, carrying a packing
     * identity nobody chose in this edit.
     */
    private function validateVariantLabelNeedsALink(Validator $validator, ?Item $item): void
    {
        if (! $this->filled('variant_label')) {
            return;
        }

        $linkAfterThisWrite = $this->has('variant_of_item_id')
            ? $this->input('variant_of_item_id')
            : $item?->variant_of_item_id;

        if ($linkAfterThisWrite === null || $linkAfterThisWrite === '') {
            $validator->errors()->add(
                'variant_label',
                'A pack-variant label needs the variant link it describes. Point this item at its base product, or leave the label empty.',
            );
        }
    }

    /**
     * Clearing the link clears the label with it, even when the payload never
     * mentions the label. The pair is one fact — "this item is the 840/box
     * pouch OF that product" — and half of it is not a smaller truth, it is a
     * stale one waiting to be relinked. The migration and ItemResource both
     * describe a base product as carrying null for both fields; this is what
     * keeps that true.
     */
    protected function prepareVariantLabelForUnlink(array $validated): array
    {
        if (! array_key_exists('variant_of_item_id', $validated)) {
            return $validated;
        }

        if ($validated['variant_of_item_id'] === null || $validated['variant_of_item_id'] === '') {
            $validated['variant_label'] = null;
        }

        return $validated;
    }
}
