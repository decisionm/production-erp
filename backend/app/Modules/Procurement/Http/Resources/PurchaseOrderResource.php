<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purchase order as the list, the show endpoint and every lifecycle
 * action return it. Phase 6 additions, all additive (every earlier key
 * keeps its place and meaning):
 *
 *   document_number   "PO-{id}" — the list's `q` grammar and every trace's name
 *   tally             TallyLink|null — status + flags + link ONLY
 *                     (TallySyncLinkService, stamped by PurchaseOrderService
 *                     on every row it returns); null = no queue entry
 *   tally_staging     what the Tally side made of the order (disabled /
 *                     refused / enqueued / dismissed, with reasons — plus
 *                     an `after` note when the order was cancelled or
 *                     closed only AFTER the agent had collected the
 *                     voucher) — written only by PurchaseOrderService::
 *                     recordTallyStaging; null until the order was sent
 *                     and judged
 *   receipts_count / revisions_count
 *   closed_* / cancelled_*   the lifecycle record (reason, actor id, time)
 *   can               {amend, close, cancel, send} — PurchaseOrderService::
 *                     abilities(), the SAME predicate the actions enforce,
 *                     so the frontend never re-derives the state machine
 *   revisions         show only (whenLoaded) — PurchaseOrderRevisionResource
 *   receipts          show only (whenLoaded) — one summary row per arrival
 *                     (id, document_number, receipt_key, references, date,
 *                     warehouse_id, quantity, lines_count, tally link)
 *
 * FC-06: the rate lives on the lines (PurchaseOrderLineResource gates it)
 * and inside an amend revision (PurchaseOrderRevisionResource gates it);
 * nothing here prints one.
 */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrder $order */
        $order = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $order->documentNumber(),
            'status' => $this->status->value,
            // 'tally' = a read-only mirror of the order living in Tally.
            'source' => $this->source ?? 'erp',
            'tally_order_no' => $this->tally_order_no,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            // ---- Phase 6 -------------------------------------------------------
            'tally' => $order->tallyLink,
            'tally_staging' => $this->tally_staging,
            'receipts_count' => $this->whenCounted('receipts'),
            'revisions_count' => $this->whenCounted('revisions'),
            'closed_reason' => $this->closed_reason,
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,
            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            // Stamped by the service on every row it returns; asked for
            // afresh only if a caller handed us an undecorated row.
            'can' => $order->can ?? app(PurchaseOrderService::class)->abilities($order),
            'revisions' => PurchaseOrderRevisionResource::collection($this->whenLoaded('revisions')),
            'receipts' => $this->whenLoaded('receipts', fn () => $order->receipts
                ->map(fn (GoodsReceiptNote $receipt) => self::receiptSummary($receipt))
                ->values()
                ->all()),
        ];
    }

    /**
     * One arrival as the ORDER lists it — identity, keys, date, store,
     * quantity, line count and the Receipt Note link. No line detail, no
     * lot, no rate: that is GET goods-receipts/{grn} and the trace.
     *
     * @return array<string, mixed>
     */
    private static function receiptSummary(GoodsReceiptNote $receipt): array
    {
        $quantity = '0.0000';
        if ($receipt->relationLoaded('lines')) {
            foreach ($receipt->lines as $line) {
                $quantity = bcadd($quantity, (string) $line->quantity, 4);
            }
        }

        return [
            'id' => $receipt->id,
            'document_number' => $receipt->documentNumber(),
            'receipt_key' => $receipt->receipt_key,
            'reference' => $receipt->reference,
            'receipt_note_reference' => $receipt->receipt_note_reference,
            'tracking_number' => $receipt->tracking_number,
            'received_date' => $receipt->received_date?->toIso8601String(),
            'warehouse_id' => $receipt->warehouse_id,
            'quantity' => $quantity,
            'lines_count' => $receipt->relationLoaded('lines') ? $receipt->lines->count() : null,
            'tally' => $receipt->tallyLink,
        ];
    }
}
