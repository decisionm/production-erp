<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\FinishedCartonResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\FinishedCartonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FinishedCartonController extends Controller
{
    public function __construct(private readonly FinishedCartonService $cartons) {}

    /** Generate (idempotent) the batch's carton identities, ready to print. */
    public function generate(Request $request, ShiftProductionEntry $shiftProductionEntry): AnonymousResourceCollection
    {
        return FinishedCartonResource::collection(
            $this->cartons->generateFor($shiftProductionEntry, $request->user()?->id),
        );
    }

    /** Reprint is a plain read — identities never change. */
    public function index(ShiftProductionEntry $shiftProductionEntry): AnonymousResourceCollection
    {
        return FinishedCartonResource::collection(
            $this->cartons->listFor($shiftProductionEntry),
        );
    }

    /** One scanned carton, traced back to its batch. */
    public function lookup(string $cartonNo): FinishedCartonResource
    {
        return FinishedCartonResource::make($this->cartons->lookup($cartonNo));
    }
}
