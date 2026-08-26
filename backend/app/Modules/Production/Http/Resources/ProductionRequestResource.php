<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Services\ProductionRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One production request as the floor's queue and every lifecycle action
 * return it.
 *
 *   request_number  "PR-{id}" — what the store and the floor quote
 *   priority        dense, 1-based, rewritten wholesale by reorder()
 *   status          queued | in_progress | produced | cancelled. NONE of
 *                   these is a batch (invariant 2): `in_progress` is a
 *                   person saying they picked the job up, never the ERP
 *                   noticing a shift entry.
 *   sales_order     {id, customer_name} — WHY the floor is being asked.
 *                   Stub-shaped rather than a full SalesOrderResource: this
 *                   is Production's screen and it needs the order's identity,
 *                   not its lines, its totals or its trace.
 *   can             {start, cancel, reorder} as ProductionRequestService::
 *                   abilities computed them — the SAME predicate the actions
 *                   enforce, so no screen re-derives the state machine
 *                   (the PurchaseOrderResource pattern).
 *
 * FC-06: no rate, no amount, no vendor. A production request is about pieces
 * and priority, so it needs no finance standing to read and grants none.
 */
class ProductionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionRequest $productionRequest */
        $productionRequest = $this->resource;

        return [
            'id' => $productionRequest->id,
            'request_number' => $productionRequest->documentNumber(),
            'priority' => (int) $productionRequest->priority,
            'status' => $productionRequest->status->value,

            'item' => $productionRequest->relationLoaded('item') && $productionRequest->item !== null
                ? [
                    'id' => (int) $productionRequest->item->id,
                    'sku' => $productionRequest->item->sku,
                    'name' => $productionRequest->item->name,
                ]
                : null,

            // Decimal string, 4dp — the same precision the sales order line
            // and the hold behind it carry.
            'quantity' => (string) $productionRequest->quantity,

            'sales_order_line_id' => (int) $productionRequest->sales_order_line_id,
            'sales_order' => $this->salesOrder($productionRequest),

            'requested_by' => $productionRequest->requested_by,
            'requested_at' => $productionRequest->created_at?->toIso8601String(),
            'cancelled_reason' => $productionRequest->cancelled_reason,

            // `?? abilities()` for the same reason PurchaseOrderResource does
            // it: every service path stamps `can`, and a row that reached a
            // resource undecorated still answers with the real predicate
            // instead of a null the screen would read as "nothing allowed".
            'can' => $productionRequest->can ?? app(ProductionRequestService::class)->abilities($productionRequest),
        ];
    }

    /** @return array{id: int, document_number: string, customer_name: ?string}|null */
    private function salesOrder(ProductionRequest $request): ?array
    {
        $order = $request->salesOrderLine?->salesOrder;

        return $order === null ? null : [
            'id' => (int) $order->id,
            'document_number' => $order->documentNumber(),
            'customer_name' => $order->customer?->name,
        ];
    }
}
