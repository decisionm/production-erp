<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\CancelMaterialRequestRequest;
use App\Modules\Inventory\Http\Requests\ListMaterialRequestsRequest;
use App\Modules\Inventory\Http\Requests\StoreMaterialRequestRequest;
use App\Modules\Inventory\Http\Resources\MaterialRequestResource;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Services\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The production material request and the STORE'S QUEUE.
 *
 * Thin by the module pattern: validate in the FormRequest, do the work in
 * MaterialRequestService, shape it in the Resource. Every lifecycle step is
 * a POST — the surface is append-only, there is no PUT and no DELETE, and
 * a cancelled request stays on the record with its reason rather than
 * disappearing.
 */
class MaterialRequestController extends Controller
{
    public function __construct(private readonly MaterialRequestService $requests) {}

    /** THE STORE'S QUEUE — filtered server-side; see the FormRequest. */
    public function index(ListMaterialRequestsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $filters['per_page'] = $this->requests->perPage($request->integer('per_page') ?: null);

        return MaterialRequestResource::collection($this->requests->queue($filters));
    }

    public function show(MaterialRequest $materialRequest): MaterialRequestResource
    {
        return MaterialRequestResource::make($this->requests->show($materialRequest));
    }

    public function store(StoreMaterialRequestRequest $request): JsonResponse
    {
        $created = $this->requests->create($request->validated(), $request->user()?->id);

        return MaterialRequestResource::make($created)->response()->setStatusCode(201);
    }

    public function submit(MaterialRequest $materialRequest): MaterialRequestResource
    {
        return MaterialRequestResource::make($this->requests->submit($materialRequest));
    }

    public function cancel(CancelMaterialRequestRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return MaterialRequestResource::make(
            $this->requests->cancel($materialRequest, $request->validated()['reason'], $request->user()?->id),
        );
    }
}
