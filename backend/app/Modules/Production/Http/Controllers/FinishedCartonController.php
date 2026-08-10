<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\CartonInternalTraceResource;
use App\Modules\Production\Http\Resources\FinishedCartonLabelResource;
use App\Modules\Production\Http\Resources\FinishedCartonResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\FinishedCartonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FinishedCartonController extends Controller
{
    public function __construct(private readonly FinishedCartonService $cartons) {}

    /**
     * Generate (idempotent) the batch's carton identities, ready to print.
     * Label shape: the scan spine plus completion date + shift
     * (DEC-20260810-001) — see FinishedCartonLabelResource.
     */
    public function generate(Request $request, ShiftProductionEntry $shiftProductionEntry): AnonymousResourceCollection
    {
        return FinishedCartonLabelResource::collection(
            $this->cartons->generateFor($shiftProductionEntry, $request->user()?->id),
        );
    }

    /** Reprint is a plain read — identities never change. */
    public function index(ShiftProductionEntry $shiftProductionEntry): AnonymousResourceCollection
    {
        return FinishedCartonLabelResource::collection(
            $this->cartons->listFor($shiftProductionEntry),
        );
    }

    /**
     * One scanned carton, traced back to its batch. The PUBLIC tier — its
     * response shape is frozen byte-for-byte by DEC-20260810-001: no cost,
     * no rate, no lot identity, no completion metadata.
     */
    public function lookup(string $cartonNo): FinishedCartonResource
    {
        return FinishedCartonResource::make($this->cartons->lookup($cartonNo));
    }

    /**
     * The INTERNAL tier behind the same scan (DEC-20260810-001): completion
     * datetime, shift, day-bin lot attribution, batch costing rate. Reached
     * only through the carton-trace permission gate (routes/api.php).
     */
    public function trace(string $cartonNo): CartonInternalTraceResource
    {
        return CartonInternalTraceResource::make($this->cartons->internalTrace($cartonNo));
    }
}
