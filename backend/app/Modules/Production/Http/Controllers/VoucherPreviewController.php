<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Http\JsonResponse;

/**
 * The voucher Tally is about to receive for one shift production entry,
 * resolved against real masters — shown before approval posts it, not after
 * it fails. Read-only.
 */
class VoucherPreviewController extends Controller
{
    public function __construct(private readonly VoucherPreviewService $preview) {}

    public function __invoke(ShiftProductionEntry $shiftProductionEntry): JsonResponse
    {
        return response()->json(['data' => $this->preview->forShiftProductionEntry($shiftProductionEntry)]);
    }
}
