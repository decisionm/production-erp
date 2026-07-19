<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Http\Requests\StoreMaintenanceScheduleRequest;
use App\Modules\Maintenance\Http\Resources\MaintenanceScheduleResource;
use App\Modules\Maintenance\Http\Resources\MaintenanceWorkOrderResource;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaintenanceScheduleController extends Controller
{
    public function __construct(private readonly MaintenanceScheduleService $schedules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaintenanceScheduleResource::collection($this->schedules->paginate($request->integer('asset_id') ?: null));
    }

    public function store(StoreMaintenanceScheduleRequest $request): MaintenanceScheduleResource
    {
        return MaintenanceScheduleResource::make($this->schedules->create($request->validated()));
    }

    public function generateDue(): JsonResponse
    {
        $created = $this->schedules->generateDueWorkOrders();

        return response()->json(['data' => MaintenanceWorkOrderResource::collection($created)]);
    }
}
