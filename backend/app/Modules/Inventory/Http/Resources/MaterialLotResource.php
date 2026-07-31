<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_id' => $this->grn_id,
            'goods_receipt_note_line_id' => $this->goods_receipt_note_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'supplier_lot_no' => $this->supplier_lot_no,
            'received_date' => $this->received_date?->toDateString(),
            'bag_count' => $this->bag_count,
            'bag_weight_kg' => $this->bag_weight_kg,
            'total_received_kg' => $this->total_received_kg,
            'bags' => MaterialBagResource::collection($this->whenLoaded('bags')),
            // Receipt provenance so the lot register can show — and link to —
            // the goods receipt this lot arrived on, with the price paid and
            // the exact date+time it was received. Only present when the
            // caller eager-loaded the relations (the lot register does).
            'receipt' => $this->when(
                $this->relationLoaded('grn') && $this->grn !== null,
                fn () => [
                    'goods_receipt_note_id' => $this->grn->id,
                    'purchase_order_id' => $this->grn->purchase_order_id,
                    'received_at' => $this->grn->received_date?->toIso8601String(),
                    'unit_cost' => $this->relationLoaded('goodsReceiptLine')
                        ? $this->goodsReceiptLine?->unit_cost
                        : null,
                ],
            ),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
