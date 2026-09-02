<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\ListMeasuringInstrumentsRequest;
use App\Modules\Quality\Http\Requests\StoreMeasuringInstrumentRequest;
use App\Modules\Quality\Http\Resources\MeasuringInstrumentResource;
use App\Modules\Quality\Services\MeasuringInstrumentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeasuringInstrumentController extends Controller
{
    public function __construct(private readonly MeasuringInstrumentService $instruments) {}

    public function index(ListMeasuringInstrumentsRequest $request): AnonymousResourceCollection
    {
        return MeasuringInstrumentResource::collection($this->instruments->paginate(
            dueOnly: $request->dueOnly(),
            perPage: $request->perPage(),
            sort: $request->sort(),
        ));
    }

    public function store(StoreMeasuringInstrumentRequest $request): MeasuringInstrumentResource
    {
        return MeasuringInstrumentResource::make($this->instruments->create($request->validated()));
    }
}
