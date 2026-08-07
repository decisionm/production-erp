<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinishedCartonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carton_no' => $this->carton_no,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'pieces' => $this->pieces,
            'is_partial' => $this->is_partial,
            'status' => $this->status,
            'delivery_id' => $this->delivery_id,
            // NET WEIGHT ONLY. Net = pieces × the run's resolved unit weight
            // ("one bottle, one weight", DEC-20260805-005 — the same figure
            // every stored kilogram is computed from). Null when the run
            // resolved no weight: a missing figure prints missing, never
            // interpolated. GROSS is deliberately absent — it needs the empty
            // carton's tare weight, which exists nowhere in the data
            // (PENDING-OWNER-QUESTIONS Q15). Do not derive one.
            'net_weight_kg' => $this->whenLoaded('entry', function () {
                $grams = $this->entry->resolvedUnitWeightGrams(
                    $this->relationLoaded('item') ? $this->item : null,
                );

                return $grams === null
                    ? null
                    : bcdiv(bcmul((string) $this->pieces, $grams, 6), '1000', 3);
            }),
            // CUSTOMER/PO — blank-capable by design, and blank today: the
            // schema has NO linkage from a production batch to a sales order
            // (cartons meet an order only later, at dispatch scan). When a
            // real linkage exists this block gets filled; until then it is
            // honestly null. Never fabricate one for the label's sake.
            'sales_order' => null,
            // The traceability spine: which batch, machine, shift and date
            // this physical box came from.
            'batch' => $this->whenLoaded('entry', fn () => [
                'shift_production_entry_id' => $this->entry->id,
                'batch_number' => $this->entry->batch_number,
                'production_date' => $this->entry->production_date?->toDateString(),
                'machine' => $this->entry->relationLoaded('workCenter') ? $this->entry->workCenter?->name : null,
                'shift' => $this->entry->relationLoaded('shift') ? $this->entry->shift?->name : null,
                // The run's box size, for the label's own "nos per box" line —
                // a partial carton's pieces differ from it, and pack variants
                // of one bottle are told apart by exactly this figure
                // (DEC-20260806-011).
                'nos_per_box' => $this->entry->nos_per_box ?? ($this->entry->config_snapshot['nos_per_box'] ?? null),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
