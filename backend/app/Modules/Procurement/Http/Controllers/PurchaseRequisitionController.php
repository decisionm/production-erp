<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StorePurchaseRequisitionRequest;
use App\Modules\Procurement\Http\Resources\PurchaseRequisitionResource;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Services\PurchaseRequisitionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseRequisitionController extends Controller
{
    public function __construct(private readonly PurchaseRequisitionService $requisitions) {}

    public function index(): AnonymousResourceCollection
    {
        return PurchaseRequisitionResource::collection($this->requisitions->paginate());
    }

    public function store(StorePurchaseRequisitionRequest $request): PurchaseRequisitionResource
    {
        $requisition = $this->requisitions->create($request->validated(), $request->user()?->id);

        return PurchaseRequisitionResource::make($requisition);
    }

    public function approve(PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
    {
        return PurchaseRequisitionResource::make($this->requisitions->approve($purchaseRequisition));
    }

    public function reject(PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
    {
        return PurchaseRequisitionResource::make($this->requisitions->reject($purchaseRequisition));
    }
}
