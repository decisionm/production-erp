<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreWorkCenterRequest;
use App\Modules\Production\Http\Requests\UpdateWorkCenterRequest;
use App\Modules\Production\Http\Resources\WorkCenterResource;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\WorkCenterService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkCenterController extends Controller
{
    public function __construct(private readonly WorkCenterService $workCenters) {}

    public function index(): AnonymousResourceCollection
    {
        return WorkCenterResource::collection($this->workCenters->paginate());
    }

    public function store(StoreWorkCenterRequest $request): WorkCenterResource
    {
        return WorkCenterResource::make($this->workCenters->create($request->validated()));
    }

    public function update(UpdateWorkCenterRequest $request, WorkCenter $workCenter): WorkCenterResource
    {
        return WorkCenterResource::make($this->workCenters->update($workCenter, $request->validated()));
    }
}
