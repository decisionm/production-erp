<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Inventory\Services\FulfilmentQueueService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sales order as the list and the show endpoint return it (Phase 3.5
 * additions marked). `trace` rides ONLY when SalesOrderService::show()
 * built it — inside `data`, beside the other keys, the same place
 * TallySyncEntryResource puts `history`; the list never carries it.
 *
 * `ready_for_dispatch` is Inventory's answer, not Sales': every line of a
 * LIVE order covered by delivered + still-held pieces. It is a badge and
 * gates nothing — dispatch remains the Delivery flow, untouched (Q27).
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
            // The CUSTOMER's own purchase-order number. No longer display-only:
            // under DEC-20260831-012 this string is the MATCH KEY the Tally
            // Sales-invoice importer joins on, so an order without one can
            // never be matched to the invoice Tally raises for it. Still no
            // voucher is emitted from it — the ERP posts nothing to Tally.
            'customer_po_reference' => $this->customer_po_reference,
            // What TALLY says about this order, imported — never posted.
            // Tally creates the Sales Invoice, the e-invoice and the e-way
            // details (DEC-20260831-012); this is the ERP reading that back.
            //
            // It is deliberately BESIDE `status` and not folded into it: the
            // order's own lifecycle is driven by what the factory did
            // (delivery), and being invoiced in another book is a different
            // fact. Whether a Tally invoice should also close an order is a
            // business rule nobody has stated, so nothing here asserts it.
            'tally_invoice' => $this->tallyInvoiceBlock($order),
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
            // READY FOR DISPATCH: every line covered by what has already
            // been delivered plus what is still HELD for it. Server-computed
            // through Inventory's own service (never its tables) so the badge
            // and the store's fulfilment queue can never tell two stories
            // about the same order.
            //
            // IT GATES NOTHING (Q27 untouched) — the Delivery flow refuses
            // and permits exactly what it did before; this is a badge.
            //
            // `?? ` for the same reason PurchaseOrderResource does it: the
            // property is a stamping seam a future bulk read can fill for a
            // whole page, and until something does, this asks the service.
            // ONE query per LIVE order on a list page — a draft, a cancelled
            // or a completed order answers false without asking anything, but
            // a list filtered to open orders is all live ones, so this is a
            // query per row until the seam is used.
            'ready_for_dispatch' => $order->ready_for_dispatch
                ?? app(FulfilmentQueueService::class)->readyForDispatch($order),
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

    /**
     * The Tally Sales voucher(s) matched to this order, or a plain "not yet".
     *
     * FC-06 is respected by omission as much as by content: this carries the
     * voucher number, its date and its state — not a rate and not a cost.
     *
     * @return array<string, mixed>
     */
    private function tallyInvoiceBlock(SalesOrder $order): array
    {
        $matched = $order->tallyInvoices
            ->filter(fn ($i) => $i->match_state->isMatched())
            ->values();

        return [
            'invoiced_in_tally' => $matched->isNotEmpty(),
            'vouchers' => $matched->map(fn ($i) => [
                'voucher_number' => $i->voucher_number,
                'voucher_date' => $i->voucher_date?->toDateString(),
                'amount' => $i->amount,
            ])->all(),
        ];
    }
}
