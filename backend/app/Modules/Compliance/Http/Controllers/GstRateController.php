<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Http\Requests\ListGstRatesRequest;
use App\Modules\Compliance\Http\Requests\StoreGstRateRequest;
use App\Modules\Compliance\Http\Requests\UpdateGstRateRequest;
use App\Modules\Compliance\Http\Resources\GstRateResource;
use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Services\GstRateService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GstRateController extends Controller
{
    public function __construct(private readonly GstRateService $rates) {}

    public function index(ListGstRatesRequest $request): AnonymousResourceCollection
    {
        return GstRateResource::collection($this->rates->paginate(
            (int) ($request->validated('per_page') ?? 20),
            $request->validated('sort'),
        ));
    }

    public function store(StoreGstRateRequest $request): GstRateResource
    {
        return GstRateResource::make($this->rates->create($request->validated()));
    }

    public function update(UpdateGstRateRequest $request, GstRate $gstRate): GstRateResource
    {
        return GstRateResource::make($this->rates->update($gstRate, $request->validated()));
    }
}
