<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Item $item */
        $item = $this->resource;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            // True while the SKU is the placeholder the masters pull seeded
            // from the Tally name; a manual SKU edit clears it (P5-02).
            'sku_provisional' => (bool) $this->sku_provisional,
            // THE TALLY WIRE KEY. Every voucher line carries this string and
            // Tally matches on it, which is why UpdateItemRequest refuses to
            // rename a Tally-linked item at all.
            'name' => $this->name,
            // THE ERP'S OWN LABEL, when somebody has given one. Null means
            // "nobody has", and every reader falls back to `name` — it is
            // deliberately not defaulted server-side, so the difference
            // between a chosen label and Tally's spelling stays visible.
            'display_name' => $this->display_name,
            // The base product this item is a pack variant of, and the words
            // that tell it from its siblings (DEC-20260821-001,
            // DEC-20260806-011). Both null on a base product.
            'variant_of_item_id' => $this->variant_of_item_id,
            'variant_label' => $this->variant_label,
            // WHAT KIND OF THING THIS IS. Null is a real state — "not recorded
            // yet" — and is NOT ItemCategory::Other. The group-to-category
            // mapping is settled (DEC-20260827-001); which categories each
            // DOCUMENT may use is Q59, and that is still open.
            'category' => $item->category?->value,
            /*
             * Tally's stock group, mirrored into `item_groups` and until now
             * write-only — its own migration records that "nothing in the
             * application reads it". Now the item list does, because it is the
             * taxonomy the catalogue actually has — and since
             * DEC-20260827-001 it is what a category is derived from.
             *
             * PRESENT ONLY WHERE IT WAS EAGER-LOADED, and that gate is the
             * whole design of this key rather than a caution. THIS RESOURCE
             * IS EMBEDDED ~35 TIMES — SalesOrderLineResource,
             * PurchaseOrderLineResource, DeliveryLineResource,
             * StockBalanceResource, BomLineResource and the rest all render
             * it through `ItemResource::make($this->whenLoaded('item'))`,
             * and none of those callers loads `item.group`. An ungated
             * `$item->group?->name` would lazy-load one query PER LINE
             * there: a 20-order page at 5 lines each is 100 extra queries,
             * and it would silently break SalesOrderService::WITH's stated
             * invariant that "the resource never lazy-loads".
             *
             * So the two paths that DO want the group load it themselves —
             * the list through ItemService::paginate(), the single-item
             * actions through ItemController::withGroup() — and everywhere
             * else the key is simply absent. The frontend already reads it
             * that way (itemIdentity.ts `itemGroupName()` returns null and
             * the Group column renders a dash), which is the honest render
             * for "not asked for" and is what an ungated read would have
             * turned into a wrong answer whenever somebody forgot.
             */
            'item_group' => $this->whenLoaded('group', fn (): ?string => $item->group?->name),
            'description' => $this->description,
            'uom' => $this->uom,
            'hsn_sac_code' => $this->hsn_sac_code,
            'reorder_level' => $this->reorder_level,
            'nominal_weight_grams' => $this->nominal_weight_grams,
            // Product packing master — Complete Batch prefill standards.
            'nos_per_tray' => $this->nos_per_tray,
            'trays_per_box' => $this->trays_per_box,
            'nos_per_box' => $this->nos_per_box,
            // Pouch standards — presence (>= 1) is what makes the frontend
            // show the pouch fields for an item; null keeps them hidden.
            'nos_per_pouch' => $this->nos_per_pouch,
            'pouches_per_box' => $this->pouches_per_box,
            // Molding standards — Start Batch snapshots these onto the shift
            // entry for the expected-output engine.
            'colour' => $this->colour,
            'standard_cycle_time' => $this->standard_cycle_time,
            'standard_cavities' => $this->standard_cavities,
            'tracking_type' => $this->tracking_type->value,
            'is_active' => $this->is_active,
            // Whether the floor may ask the store for this item. It is a
            // CONFIGURATION the owner controls, so it has to be visible and
            // editable here — the eligibility rule is only honest if the
            // residue it cannot infer can be corrected without a code change.
            'is_production_input' => (bool) $this->is_production_input,
            // Tally provenance — the UI uses tally_stock_item_guid to mark
            // sku/name read-only for Tally-sourced items (§3 split-ownership).
            'tally_stock_item_guid' => $this->tally_stock_item_guid,
            'tally_synced_at' => $this->tally_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Archived-by-soft-delete. Archive clears is_active rather than
            // trashing the row, but the Tally masters pull restores trashed
            // items, so the state is stated rather than inferred.
            'archived_at' => $item->deleted_at?->toIso8601String(),
            /*
             * WHAT MAY BE DONE TO THIS RECORD, decided by the server
             * (DEC-20260817-002) — see WarehouseResource for the full note.
             * `delete` is null (undetermined, ask `show`) on a list, because
             * an item's declaration is ~40 COUNT queries; authoritative on
             * show and on every action, stamped by the controller.
             */
            'can' => $item->can ?? app(ItemService::class)
                ->abilities($item, resolveDelete: false, user: $request->user()),
        ];
    }
}
