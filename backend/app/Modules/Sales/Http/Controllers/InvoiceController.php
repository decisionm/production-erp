<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\ListInvoicesRequest;
use App\Modules\Sales\Http\Resources\InvoiceResource;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE RETIRED SALES INVOICE, READ ONLY (DEC-20260903-004).
 *
 * `store` and `issue` are gone with the document they wrote: Tally originates
 * the sales invoice, the e-invoice and the IRN (DEC-20260831-012) and the ERP
 * imports and matches that voucher (DEC-20260902-046). What is left here
 * reads history — every invoice the ERP wrote before the retirement stays
 * listable and readable, and is never edited or deleted.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SalesDocumentQuery $query,
    ) {}

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return InvoiceResource::collection($this->invoices->paginate($this->query->perPage($filters), $filters));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($this->invoices->show($invoice));
    }
}
