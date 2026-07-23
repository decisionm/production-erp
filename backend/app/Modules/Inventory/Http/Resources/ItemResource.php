<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'uom' => $this->uom,
            'hsn_sac_code' => $this->hsn_sac_code,
            'reorder_level' => $this->reorder_level,
            'nominal_weight_grams' => $this->nominal_weight_grams,
            'tracking_type' => $this->tracking_type->value,
            'is_active' => $this->is_active,
            // Tally provenance — the UI uses tally_stock_item_guid to mark
            // sku/name read-only for Tally-sourced items (§3 split-ownership).
            'tally_stock_item_guid' => $this->tally_stock_item_guid,
            'tally_synced_at' => $this->tally_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
