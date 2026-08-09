<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreShiftRequest;
use App\Modules\Production\Http\Resources\ShiftResource;
use App\Modules\Production\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * `?active=1` restricts to shifts the factory currently runs — the
     * operational contract every picker and the dashboard rail consume.
     * Without the param the full set answers, retired rows included: the
     * admin screen and anything resolving history need those. Same shape
     * as WorkCenterController::index, deliberately.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        return ShiftResource::collection($this->shifts->paginate(activeOnly: $active));
    }

    public function store(StoreShiftRequest $request): ShiftResource
    {
        return ShiftResource::make($this->shifts->create($request->validated()));
    }
}
