<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a lot's cost history. Only ever reached through the
 * finance-gated cost-version endpoints — rates are Owner/Accounts data.
 */
class MaterialCostVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'material_lot_id' => $this->material_lot_id,
            'rate_per_kg' => $this->rate_per_kg,
            'kind' => $this->kind?->value,
            // The recorder's own words. Distinct from the top-level 'note'
            // the endpoints return, which states what a version does to
            // stock (nothing).
            'note' => $this->note,
            // The version this one replaced. null on the original receipt
            // rate — the end of the chain, and the number that never moves.
            'supersedes_id' => $this->supersedes_id,
            'created_by' => $this->whenLoaded(
                'createdBy',
                fn () => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
