<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ListPurchaseRequisitionsRequest;
use App\Modules\Procurement\Http\Requests\StorePurchaseRequisitionRequest;
use App\Modules\Procurement\Http\Resources\PurchaseRequisitionResource;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Services\PurchaseRequisitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseRequisitionController extends Controller
{
    public function __construct(private readonly PurchaseRequisitionService $requisitions) {}

    public function index(ListPurchaseRequisitionsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return PurchaseRequisitionResource::collection(
            $this->requisitions->paginate((int) ($filters['per_page'] ?? 20), $filters),
        );
    }

    public function store(StorePurchaseRequisitionRequest $request): PurchaseRequisitionResource
    {
        $requisition = $this->requisitions->create($request->validated(), $request->user()?->id);

        return PurchaseRequisitionResource::make($requisition);
    }

    public function approve(Request $request, PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
    {
        return PurchaseRequisitionResource::make(
            $this->requisitions->approve($purchaseRequisition, $request->user()?->id),
        );
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
    {
        return PurchaseRequisitionResource::make(
            $this->requisitions->reject($purchaseRequisition, $request->user()?->id),
        );
    }

    public function withdraw(Request $request, PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
    {
        return PurchaseRequisitionResource::make(
            $this->requisitions->withdraw($purchaseRequisition, (int) $request->user()->id),
        );
    }
}
