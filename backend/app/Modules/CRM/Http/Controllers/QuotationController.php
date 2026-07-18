<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Http\Requests\StoreQuotationRequest;
use App\Modules\CRM\Http\Resources\QuotationResource;
use App\Modules\CRM\Models\Quotation;
use App\Modules\CRM\Services\QuotationService;
use App\Modules\Sales\Http\Resources\SalesOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(): AnonymousResourceCollection
    {
        return QuotationResource::collection($this->quotations->paginate());
    }

    public function store(StoreQuotationRequest $request): QuotationResource
    {
        $quotation = $this->quotations->create($request->validated(), $request->user()?->id);

        return QuotationResource::make($quotation);
    }

    public function send(Quotation $quotation): QuotationResource
    {
        return QuotationResource::make($this->quotations->send($quotation));
    }

    public function accept(Quotation $quotation): JsonResponse
    {
        $result = $this->quotations->accept($quotation, request()->user()?->id);

        return response()->json([
            'data' => [
                'quotation' => QuotationResource::make($result['quotation']),
                'sales_order' => SalesOrderResource::make($result['sales_order']),
            ],
        ]);
    }

    public function reject(Quotation $quotation): QuotationResource
    {
        return QuotationResource::make($this->quotations->reject($quotation));
    }
}
