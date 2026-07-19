<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\RecordCalibrationRequest;
use App\Modules\Quality\Http\Resources\MeasuringInstrumentResource;
use App\Modules\Quality\Models\MeasuringInstrument;
use App\Modules\Quality\Services\MeasuringInstrumentService;

class CalibrationRecordController extends Controller
{
    public function __construct(private readonly MeasuringInstrumentService $instruments) {}

    public function index(MeasuringInstrument $instrument): MeasuringInstrumentResource
    {
        return MeasuringInstrumentResource::make($this->instruments->calibrationHistory($instrument));
    }

    public function store(RecordCalibrationRequest $request, MeasuringInstrument $instrument): MeasuringInstrumentResource
    {
        return MeasuringInstrumentResource::make($this->instruments->recordCalibration($instrument, $request->validated()));
    }
}
