<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\MaterialLotResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One goods receipt as the list, the show endpoint and the store response
 * return it. Phase 6 additions, additive: `document_number` ("GRN-{id}",
 * the list's `q` grammar) and `tally` — the Receipt Note's TallyLink
 * (status + flags + link only; TallySyncLinkService), stamped by
 * GoodsReceiptService on every row it returns, null when no entry exists.
 * FC-06: the rate lives on the lines and lots (their resources gate it);
 * nothing here prints one.
 */
class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GoodsReceiptNote $receipt */
        $receipt = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $receipt->documentNumber(),
            'receipt_key' => $this->receipt_key,
            'purchase_order_id' => $this->purchase_order_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'reference' => $this->reference,
            'receipt_note_reference' => $this->receipt_note_reference,
            'tracking_number' => $this->tracking_number,
            'received_date' => $this->received_date?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => GoodsReceiptNoteLineResource::collection($this->whenLoaded('lines')),
            'material_lots' => MaterialLotResource::collection($this->whenLoaded('materialLots')),
            // TallyLink|null — status + flags + link only (TallySyncLinkService).
            'tally' => $receipt->tallyLink,
            // What staging concluded at arrival (disabled / refused / enqueued
            // — GoodsReceiptService::recordTallyStaging, the only writer).
            // NULL on receipts that predate the column. FC-06 holds on the
            // reason details by construction — see ReceiptNoteNotPostable.
            'tally_staging' => $this->tally_staging,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
