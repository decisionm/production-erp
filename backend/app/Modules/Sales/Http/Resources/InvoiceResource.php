<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One invoice as the list, the show endpoint and the create/issue responses
 * return it (Phase 3.5 additions: document_number, sales_order stub, tally,
 * trace). `tally` is stamped by InvoiceService through
 * SalesDocumentTraceService on every row it returns — null while draft (no
 * entry exists), the Sales entry's link once issued; `trace` rides only
 * from show(), inside `data`.
 *
 * `paid` is a status this ERP never sets: receipts are recorded in Tally
 * (DEC-20260809-003). It stays in the enum for the rows that already carry
 * it and nothing here — or anywhere — moves an invoice into it.
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $invoice->documentNumber(),
            'status' => $this->status->value,
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $invoice->salesOrder === null ? null : SalesOrderResource::stub($invoice->salesOrder)),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            // TallyLink|null — status + flags + link only (TallySyncLinkService).
            'tally' => $invoice->tallyLink,
            'created_at' => $this->created_at?->toIso8601String(),
            'trace' => $this->when($invoice->trace !== null, fn () => $invoice->trace),
        ];
    }
}
