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
            // THE BATCH'S QUALITY AND APPROVAL TRUTH (DEC-20260807-013). The
            // sticker on the box never changes — this block is what tells a
            // scanner whether the box may ship. packed_nos is the GROSS packed
            // count (cartons are physical boxes packed before QC nets
            // anything); qc_approved_nos is the NETTED figure once the quality
            // gate has counted, honestly null before then. The verdict is the
            // one word the UI renders loud: 'quality_rejected' boxes are also
            // refused server-side at dispatch scan.
            'quality' => $this->whenLoaded('entry', fn () => [
                'entry_status' => $this->entry->status?->value,
                'verdict' => $this->entry->cartonQualityVerdict(),
                // bcadd(_, '0', 4) canonicalizes: these columns carry no
                // Eloquent cast, so raw driver values differ (SQLite 2160,
                // MySQL '2160.0000') — the API shape must not.
                'packed_nos' => bcadd((string) ($this->entry->gross_quantity_produced ?? $this->entry->quantity_produced ?? '0'), '0', 4),
                'qc_approved_nos' => $this->entry->quality_checked_at !== null
                    ? bcadd((string) ($this->entry->quantity_produced ?? '0'), '0', 4)
                    : null,
                'qc_rejected_nos' => $this->entry->quality_checked_at !== null
                    ? $this->entry->quality_rejected_nos
                    : null,
            ]),
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
