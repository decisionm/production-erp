<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\RecordSpcMeasurementRequest;
use App\Modules\Quality\Http\Resources\SpcMeasurementResource;
use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Quality\Services\SpcMeasurementService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpcMeasurementController extends Controller
{
    public function __construct(private readonly SpcMeasurementService $measurements) {}

    public function index(SpcCharacteristic $spcCharacteristic): AnonymousResourceCollection
    {
        return SpcMeasurementResource::collection($this->measurements->paginate($spcCharacteristic));
    }

    public function store(RecordSpcMeasurementRequest $request, SpcCharacteristic $spcCharacteristic): SpcMeasurementResource
    {
        return SpcMeasurementResource::make(
            $this->measurements->record($spcCharacteristic, $request->validated(), $request->user()?->id),
        );
    }
}
