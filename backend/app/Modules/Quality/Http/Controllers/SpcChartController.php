<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Quality\Services\SpcChartService;
use Illuminate\Http\JsonResponse;

class SpcChartController extends Controller
{
    public function __construct(private readonly SpcChartService $charts) {}

    public function show(SpcCharacteristic $spcCharacteristic): JsonResponse
    {
        return response()->json(['data' => $this->charts->chart($spcCharacteristic)]);
    }
}
