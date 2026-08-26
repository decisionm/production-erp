<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sales order as the list and the show endpoint return it (Phase 3.5
 * additions marked). `trace` rides ONLY when SalesOrderService::show()
 * built it — inside `data`, beside the other keys, the same place
 * TallySyncEntryResource puts `history`; the list never carries it.
 *
 * `totals` and `can_cancel` are the model's own arithmetic and rule
 * (SalesOrder::orderedQuantity/deliveredQuantity/invoicedQuantity,
 * SalesOrder::isCancellable — the SAME predicate SalesOrderService::cancel()
 * enforces), so the button and the refusal cannot disagree.
 */
class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SalesOrder $order */
        $order = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $order->documentNumber(),
            'status' => $this->status->value,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            // Derived on every read from the FACTORY's calendar (IST), never
            // stored: an order goes overdue because the day turned, not
            // because anything was written to it. Undated and finished
            // orders are never overdue.
            'is_overdue' => $order->isOverdue(),
            'notes' => $this->notes,
            'lines' => SalesOrderLineResource::collection($this->whenLoaded('lines')),
            // Decimal strings, 4dp — the same precision the lines carry.
            'totals' => [
                'ordered_quantity' => $order->orderedQuantity(),
                'delivered_quantity' => $order->deliveredQuantity(),
                'invoiced_quantity' => $order->invoicedQuantity(),
            ],
            'deliveries_count' => $order->deliveriesCount(),
            'invoices_count' => $order->invoicesCount(),
            'can_cancel' => $order->isCancellable(),
            // The SAME predicate SalesOrderService::update() enforces, so
            // the drawer's Edit button and the server's refusal agree.
            'can_edit' => $order->isEditable(),
            'created_at' => $this->created_at?->toIso8601String(),
            'trace' => $this->when($order->trace !== null, fn () => $order->trace),
        ];
    }

    /**
     * The order as ANOTHER document names it — {id, document_number, status}
     * on a delivery, an invoice, and every trace. One shape, defined once.
     *
     * @return array{id: int, document_number: string, status: string}
     */
    public static function stub(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'document_number' => $order->documentNumber(),
            'status' => $order->status->value,
        ];
    }
}
