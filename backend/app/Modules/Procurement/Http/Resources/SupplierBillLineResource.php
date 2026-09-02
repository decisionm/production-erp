<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bill line. No FC-06 gate INSIDE the resource, deliberately: the whole
 * supplier-bill surface rides module:finance at the routes — nobody without
 * Owner/Accounts access can reach any serialization of these rows at all.
 */
class SupplierBillLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goods_receipt_note_line_id' => $this->goods_receipt_note_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount,
            // The matched arrival's quantity, for the variance the screen
            // shows (billed vs received).
            'received_quantity' => $this->whenLoaded('goodsReceiptNoteLine', fn () => $this->goodsReceiptNoteLine?->quantity),
            // DEC-20260902-015: the rejected quantity and the Rejections Out
            // reference must be VISIBLE against the matching GRN line — not
            // only on the create-form's picker, but on the RECORDED bill's
            // own detail, because a supplier credit note arrives after the
            // bill is recorded, which is when Accounts opens this screen to
            // match it. Nullable: no inspection yet, or no matched GRN line.
            'qc_rejected_quantity' => $this->whenLoaded('goodsReceiptNoteLine', fn () => $this->goodsReceiptNoteLine?->incomingInspections?->first()?->rejected_quantity),
            'qc_rejections_out_reference' => $this->whenLoaded('goodsReceiptNoteLine', fn () => $this->goodsReceiptNoteLine?->incomingInspections?->first()?->rejections_out_reference),
        ];
    }
}
