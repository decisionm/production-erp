<?php

namespace App\Modules\Quality\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomingInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goods_receipt_note_line_id' => $this->goods_receipt_note_line_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'inspected_quantity' => $this->inspected_quantity,
            'accepted_quantity' => $this->accepted_quantity,
            'rejected_quantity' => $this->rejected_quantity,
            'result' => $this->result->value,
            'inspection_date' => $this->inspection_date?->toDateString(),
            'inspected_by' => $this->whenLoaded('inspectedBy', fn () => $this->inspectedBy?->name),
            'notes' => $this->notes,
            // What the disposition actually did to the arrival's bags, and
            // the reference a Rejections Out voucher will carry once its
            // Tally shape is proven and enabled. Reference, not voucher.
            'rejections_out_reference' => $this->rejections_out_reference,
            'bag_disposition_note' => $this->bag_disposition_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
