<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreWorkCenterRequest;
use App\Modules\Production\Http\Requests\UpdateWorkCenterRequest;
use App\Modules\Production\Http\Resources\WorkCenterResource;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\WorkCenterService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkCenterController extends Controller
{
    public function __construct(private readonly WorkCenterService $workCenters) {}

    /**
     * `?active=1` restricts to machines currently in service — what every
     * production selector must send, so a retired machine cannot be picked.
     * `?active=0` lists the retired ones for the admin screen's filter.
     * Omitted returns both.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        return WorkCenterResource::collection(
            $this->workCenters->paginate(perPage: 100, activeOnly: $active),
        );
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
