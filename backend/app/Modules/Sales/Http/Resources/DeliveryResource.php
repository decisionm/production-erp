<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery as the list, the show endpoint and the dispatch response
 * return it (Phase 3.5 additions: document_number, sales_order stub,
 * customer stub, carton_count, tally, trace). `tally` and `carton_count`
 * are stamped by DeliveryService through SalesDocumentTraceService on every
 * row it returns; `trace` rides only from show(), inside `data`.
 */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Delivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $delivery->documentNumber(),
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $delivery->salesOrder === null ? null : SalesOrderResource::stub($delivery->salesOrder)),
            'customer' => $this->whenLoaded('salesOrder', fn () => $delivery->salesOrder?->relationLoaded('customer') && $delivery->salesOrder->customer !== null
                ? CustomerResource::stub($delivery->salesOrder->customer)
                : null),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'reference' => $this->reference,
            'delivered_date' => $this->delivered_date?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => DeliveryLineResource::collection($this->whenLoaded('lines')),
            'carton_count' => $delivery->cartonCount,
            // TallyLink|null — status + flags + link only (TallySyncLinkService).
            'tally' => $delivery->tallyLink,
            'created_at' => $this->created_at?->toIso8601String(),
            'trace' => $this->when($delivery->trace !== null, fn () => $delivery->trace),
        ];
    }
}
