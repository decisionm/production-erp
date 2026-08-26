<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemIdentityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE ITEM AS THE IDENTITY REVIEW SEES IT — deliberately NOT
 * {@see ItemResource}.
 *
 * That one carries the whole master (packing standards, molding standards,
 * reorder level) and a `can` block whose fallback costs a handful of COUNT
 * queries per row. This screen asks a narrower question — who is this item,
 * and what looks wrong with that — so it renders a narrow row and the query
 * count of a page stays flat.
 *
 * `suggested_category` IS READ-ONLY AND IS NEVER PERSISTED BY ANYTHING. It
 * is Tally's stock grouping restated as a proposal (Q60 is open), with the
 * confidence attached so a judgement call cannot be mistaken for a fact.
 * `null` means no suggestion — either the group is one Q60 explicitly has
 * not answered (Caps & Closures, Scrap) or the item has no group at all.
 */
class ItemIdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Item $item */
        $item = $this->resource;

        // Stamped by ItemIdentityService::itemsWithWarnings(). The fallback
        // is correct and slower — it exists so rendering one item outside
        // that path is never silently warning-free.
        $warnings = $item->identity_warnings ?? app(ItemIdentityService::class)->warningsFor($item);
        $suggested = $item->identity_suggested_category
            ?? app(ItemIdentityService::class)->suggestedCategoryFor($item);

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            // The Tally wire key, and the ERP's own label for a person.
            'name' => $item->name,
            'display_name' => $item->display_name,
            'uom' => $item->uom,
            'is_active' => (bool) $item->is_active,
            'category' => $item->category?->value,
            'item_group' => $item->group?->name,
            'tally_stock_item_guid' => $item->tally_stock_item_guid,

            'variant_of_item_id' => $item->variant_of_item_id,
            'variant_label' => $item->variant_label,
            'variant_of' => $item->variantOf === null ? null : [
                'id' => $item->variantOf->id,
                'sku' => $item->variantOf->sku,
                'name' => $item->variantOf->name,
                'display_name' => $item->variantOf->display_name,
            ],

            'suggested_category' => $suggested['category']?->value,
            'suggested_category_confidence' => $suggested['confidence'],

            /** @var list<array{class: string, label: string, note: string}> */
            'warnings' => $warnings,
        ];
    }
}
