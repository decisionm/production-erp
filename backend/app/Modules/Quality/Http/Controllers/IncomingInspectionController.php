<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\StoreIncomingInspectionRequest;
use App\Modules\Quality\Http\Resources\IncomingInspectionResource;
use App\Modules\Quality\Http\Resources\PendingIncomingInspectionLineResource;
use App\Modules\Quality\Services\IncomingInspectionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncomingInspectionController extends Controller
{
    public function __construct(private readonly IncomingInspectionService $inspections) {}

    public function index(): AnonymousResourceCollection
    {
        return IncomingInspectionResource::collection($this->inspections->paginate());
    }

    /**
     * The desk's queue: every arrival line with no inspection yet, oldest
     * first, all of them. Read-only, and served by a resource that can print
     * no rate and no vendor (FC-06) — see PendingIncomingInspectionLineResource.
     *
     * GET, so `module:quality` clears it on `quality.view` alone: reading the
     * queue is not the same permission as recording a disposition.
     */
    public function pending(): AnonymousResourceCollection
    {
        return PendingIncomingInspectionLineResource::collection($this->inspections->pendingLines());
    }

    public function store(StoreIncomingInspectionRequest $request): IncomingInspectionResource
    {
        $inspection = $this->inspections->create($request->validated(), $request->user()?->id);

        return IncomingInspectionResource::make($inspection);
    }
}
