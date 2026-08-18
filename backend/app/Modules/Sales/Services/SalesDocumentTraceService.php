<?php

namespace App\Modules\Sales\Services;

use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Production\Services\FinishedCartonService;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Http\Resources\SalesOrderResource;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Services\TallySyncLinkService;
use Illuminate\Support\Collection;

/**
 * THE CHAIN BEHIND ONE SALES DOCUMENT (Phase 3.5): where Sales reaches into
 * the two modules that hold the rest of the story — TallySync for "did the
 * voucher for this document reach Tally" (TallySyncLinkService, the ONLY
 * cross-module hop into the queue) and Production for "which cartons
 * physically left on this delivery, from which batch"
 * (FinishedCartonService::forDeliveries / countForDeliveries). Both are
 * read through the other module's Service class, never its models
 * (CLAUDE.md), and both are one query per page, never one per row.
 *
 * Two jobs, kept apart:
 *   decorate*()   stamp list/show rows with their TallyLink (and carton
 *                 count) so the resource can print a Tally column;
 *   *Trace()      the show endpoint's `trace` — the ordered chain: an
 *                 order's deliveries (lines, cartons, link) and invoices
 *                 (lines, link); a delivery's order + cartons + link; an
 *                 invoice's order + link.
 *
 * What a trace NEVER carries: a purchase rate, a supplier, a bag or lot
 * (FC-06 — those live behind Production's internal carton tier and
 * Finance's cost gate); the voucher payload (that is TallySyncEntryResource's,
 * behind its own gate — a link is status + flags + link only). Selling
 * prices (unit_price on invoice lines) are the customer's and stay.
 */
class SalesDocumentTraceService
{
    public function __construct(
        private readonly TallySyncLinkService $tallyLinks,
        private readonly FinishedCartonService $cartons,
    ) {}

    // ---- decorations ----------------------------------------------------------

    /**
     * Stamp each delivery with its Delivery Note TallyLink (or null) and its
     * scanned-carton count — two queries for the whole set.
     *
     * @param  iterable<int, Delivery>  $deliveries
     */
    public function decorateDeliveries(iterable $deliveries): void
    {
        $deliveries = Collection::make($deliveries);
        if ($deliveries->isEmpty()) {
            return;
        }

        $ids = $deliveries->map(fn (Delivery $delivery) => $delivery->id)->all();
        $links = $this->tallyLinks->forMany((new Delivery)->getMorphClass(), $ids);
        $counts = $this->cartons->countForDeliveries($ids);

        foreach ($deliveries as $delivery) {
            $delivery->tallyLink = $links[$delivery->id] ?? null;
            $delivery->cartonCount = $counts[$delivery->id] ?? 0;
        }
    }

    /**
     * Stamp each invoice with its Sales TallyLink (or null — a draft has
     * none) — one query for the whole set.
     *
     * @param  iterable<int, Invoice>  $invoices
     */
    public function decorateInvoices(iterable $invoices): void
    {
        $invoices = Collection::make($invoices);
        if ($invoices->isEmpty()) {
            return;
        }

        $links = $this->tallyLinks->forMany((new Invoice)->getMorphClass(), $invoices->map(fn (Invoice $invoice) => $invoice->id)->all());

        foreach ($invoices as $invoice) {
            $invoice->tallyLink = $links[$invoice->id] ?? null;
        }
    }

    // ---- traces -----------------------------------------------------------------

    /**
     * An order's whole chain, oldest first: every delivery with its lines,
     * the cartons that left on it and its Delivery Note link; every invoice
     * with its lines and its Sales link.
     *
     * @return array{deliveries: list<array<string, mixed>>, invoices: list<array<string, mixed>>}
     */
    public function orderTrace(SalesOrder $order): array
    {
        $order->loadMissing(['deliveries.lines.item', 'deliveries.warehouse', 'invoices.lines.item']);

        $deliveries = $order->deliveries->sortBy('id')->values();
        $invoices = $order->invoices->sortBy('id')->values();

        $this->decorateDeliveries($deliveries);
        $this->decorateInvoices($invoices);
        $cartons = $this->cartons->forDeliveries($deliveries->map(fn (Delivery $delivery) => $delivery->id)->all());

        return [
            'deliveries' => $deliveries
                ->map(fn (Delivery $delivery) => $this->deliveryRow($delivery, $cartons->get($delivery->id, collect())))
                ->all(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))->all(),
        ];
    }

    /**
     * A delivery's chain: the order it fulfils (with its customer), the
     * cartons that left on it, and its own Delivery Note link. Assumes the
     * delivery is already decorated (decorateDeliveries).
     *
     * @return array{sales_order: array<string, mixed>|null, cartons: list<array<string, mixed>>, tally: array<string, mixed>|null}
     */
    public function deliveryTrace(Delivery $delivery): array
    {
        $delivery->loadMissing('salesOrder.customer');

        return [
            'sales_order' => $delivery->salesOrder === null ? null : $this->orderStub($delivery->salesOrder, withCustomer: true),
            'cartons' => $this->cartonRows($this->cartons->forDeliveries([$delivery->id])->get($delivery->id, collect())),
            'tally' => $delivery->tallyLink,
        ];
    }

    /**
     * An invoice's chain: the order it bills (with its customer) and its own
     * Sales link. Assumes the invoice is already decorated (decorateInvoices).
     *
     * @return array{sales_order: array<string, mixed>|null, tally: array<string, mixed>|null}
     */
    public function invoiceTrace(Invoice $invoice): array
    {
        $invoice->loadMissing('salesOrder.customer');

        return [
            'sales_order' => $invoice->salesOrder === null ? null : $this->orderStub($invoice->salesOrder, withCustomer: true),
            'tally' => $invoice->tallyLink,
        ];
    }

    // ---- shapes -------------------------------------------------------------------

    /**
     * The order as another document names it: {id, document_number, status}
     * (+ customer {id, code, name} on a trace). ONE shape, defined on the
     * resource so a list row and a trace can never disagree.
     *
     * @return array<string, mixed>
     */
    public function orderStub(SalesOrder $order, bool $withCustomer = false): array
    {
        $stub = SalesOrderResource::stub($order);

        if ($withCustomer) {
            $order->loadMissing('customer');
            $stub['customer'] = $order->customer === null ? null : CustomerResource::stub($order->customer);
        }

        return $stub;
    }

    /**
     * @param  Collection<int, FinishedCarton>  $cartons
     * @return array<string, mixed>
     */
    private function deliveryRow(Delivery $delivery, Collection $cartons): array
    {
        return [
            'id' => $delivery->id,
            'document_number' => $delivery->documentNumber(),
            'reference' => $delivery->reference,
            'delivered_date' => $delivery->delivered_date?->toIso8601String(),
            'warehouse' => $delivery->warehouse === null ? null : ['id' => $delivery->warehouse->id, 'name' => $delivery->warehouse->name],
            'lines' => $delivery->lines->map(fn ($line) => [
                'item' => $line->item === null ? null : ['id' => $line->item->id, 'name' => $line->item->name],
                'quantity' => $line->quantity,
            ])->values()->all(),
            'cartons' => $this->cartonRows($cartons),
            'tally' => $delivery->tallyLink,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'document_number' => $invoice->documentNumber(),
            'status' => $invoice->status->value,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'lines' => $invoice->lines->map(fn ($line) => [
                'item' => $line->item === null ? null : ['id' => $line->item->id, 'name' => $line->item->name],
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
            ])->values()->all(),
            'tally' => $invoice->tallyLink,
        ];
    }

    /**
     * The public carton tier only: number, pieces, the batch entry it came
     * from and that batch's number. Nothing of FinishedCartonService::
     * internalTrace() (lot, rate) — FC-06.
     *
     * @param  Collection<int, FinishedCarton>  $cartons
     * @return list<array{carton_no: string, pieces: string, shift_production_entry_id: ?int, batch_no: ?string}>
     */
    private function cartonRows(Collection $cartons): array
    {
        return $cartons->sortBy('id')->values()->map(fn (FinishedCarton $carton) => [
            'carton_no' => $carton->carton_no,
            'pieces' => $carton->pieces,
            'shift_production_entry_id' => $carton->shift_production_entry_id,
            'batch_no' => $carton->entry?->batch_number,
        ])->all();
    }
}
