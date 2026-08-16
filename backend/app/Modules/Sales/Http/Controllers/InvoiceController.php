<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\ListInvoicesRequest;
use App\Modules\Sales\Http\Requests\StoreInvoiceRequest;
use App\Modules\Sales\Http\Resources\InvoiceResource;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function store(StoreInvoiceRequest $request): InvoiceResource
    {
        $invoice = $this->invoices->create($request->validated(), $request->user()?->id);

        return InvoiceResource::make($invoice);
    }

    public function issue(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($this->invoices->issue($invoice));
    }
}
