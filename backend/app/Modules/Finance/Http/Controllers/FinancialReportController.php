<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\AccountsReceivableService;
use App\Modules\Finance\Services\FinancialReportService;
use Illuminate\Http\JsonResponse;

class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly AccountsReceivableService $receivables,
    ) {}

    public function trialBalance(): JsonResponse
    {
        return response()->json(['data' => $this->reports->trialBalance()]);
    }

    public function profitAndLoss(): JsonResponse
    {
        return response()->json(['data' => $this->reports->profitAndLoss()]);
    }

    public function balanceSheet(): JsonResponse
    {
        return response()->json(['data' => $this->reports->balanceSheet()]);
    }

    public function receivables(): JsonResponse
    {
        return response()->json(['data' => $this->receivables->outstanding()]);
    }
}
