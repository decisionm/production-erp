<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\ListIncomingInspectionsRequest;
use App\Modules\Quality\Http\Requests\StoreIncomingInspectionRequest;
use App\Modules\Quality\Http\Resources\IncomingInspectionResource;
use App\Modules\Quality\Services\IncomingInspectionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncomingInspectionController extends Controller
{
    public function __construct(private readonly IncomingInspectionService $inspections) {}

    public function index(ListIncomingInspectionsRequest $request): AnonymousResourceCollection
    {
        return IncomingInspectionResource::collection($this->inspections->paginate(
            perPage: $request->perPage(),
            q: $request->term(),
            result: $request->result(),
            sort: $request->sort(),
        ));
    }

    public function store(StoreIncomingInspectionRequest $request): IncomingInspectionResource
    {
        $inspection = $this->inspections->create($request->validated(), $request->user()?->id);

        return IncomingInspectionResource::make($inspection);
    }
}
