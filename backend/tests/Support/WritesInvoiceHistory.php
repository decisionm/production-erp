<?php

namespace Tests\Support;

use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * AN ERP INVOICE ROW, WRITTEN THE ONLY WAY ONE CAN BE WRITTEN NOW.
 *
 * The ERP's own sales invoice is retired (DEC-20260903-004): the routes that
 * created and issued one are withdrawn, so a test that needs an invoice to
 * exist — because it is testing what an order carrying one does, or what the
 * list and the trace show — builds the row through the models, exactly as the
 * rows already on live sit there.
 *
 * THIS IS A FIXTURE, NOT A BACK DOOR. Nothing in the application may write an
 * invoice; these helpers exist so the surviving rules about EXISTING invoices
 * stay under test after their writer was removed. If a test used to assert
 * something about the ACT of invoicing, the honest replacement is an
 * assertion that the route is gone (see InvoiceRetiredTest), never a fixture
 * that pretends the act still happens.
 */
trait WritesInvoiceHistory
{
    /**
     * One historic invoice against an order's first line.
     *
     * @param  ?string  $quantity  defaults to the whole ordered quantity
     */
    protected function invoiceHistory(
        SalesOrder $order,
        ?string $quantity = null,
        InvoiceStatus $status = InvoiceStatus::Draft,
        ?int $createdBy = null,
        string $invoiceDate = '2026-08-12',
    ): Invoice {
        $order->loadMissing('lines');
        $line = $order->lines->first();

        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => $status,
            'invoice_date' => $invoiceDate,
            'created_by' => $createdBy,
        ]);

        $invoice->lines()->create([
            'sales_order_line_id' => $line->id,
            'item_id' => $line->item_id,
            'quantity' => $quantity ?? (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
        ]);

        return $invoice;
    }

    /**
     * A historic invoice already ISSUED — the state that used to be reached
     * through POST /sales/invoices/{id}/issue, and the state most history
     * rows on live are in.
     *
     * Written as a create-then-update, not a create with status Issued,
     * because the Tally staging listener fires on the Invoice::updated
     * transition (TallySyncEventServiceProvider) and a test that stands in
     * for the old issue endpoint has to reach the same listener the endpoint
     * reached.
     */
    protected function issuedInvoiceHistory(
        SalesOrder $order,
        ?string $quantity = null,
        ?int $createdBy = null,
        string $invoiceDate = '2026-08-12',
    ): Invoice {
        $invoice = $this->invoiceHistory($order, $quantity, InvoiceStatus::Draft, $createdBy, $invoiceDate);
        $invoice->update(['status' => InvoiceStatus::Issued]);

        return $invoice->refresh();
    }
}
