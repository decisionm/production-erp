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
            // Product packing master — Complete Batch prefill standards.
            'nos_per_tray' => $this->nos_per_tray,
            'trays_per_box' => $this->trays_per_box,
            'nos_per_box' => $this->nos_per_box,
            // Molding standards — Start Batch snapshots these onto the shift
            // entry for the expected-output engine.
            'colour' => $this->colour,
            'standard_cycle_time' => $this->standard_cycle_time,
            'standard_cavities' => $this->standard_cavities,
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
