<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Services\GstReportService;
use Illuminate\Http\JsonResponse;

class GstReportController extends Controller
{
    public function __construct(private readonly GstReportService $reports) {}

    public function gstr1(): JsonResponse
    {
        return response()->json(['data' => $this->reports->gstr1()]);
    }
}
