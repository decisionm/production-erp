<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreInvoiceRequest;
use App\Modules\Sales\Http\Resources\InvoiceResource;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\InvoiceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(): AnonymousResourceCollection
    {
        return InvoiceResource::collection($this->invoices->paginate());
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
