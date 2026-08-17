<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Production\Models\FinishedCarton;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * THE INTERNAL CARTON TRACE TIER (DEC-20260810-001), wrapping
 * FinishedCartonService::internalTrace(). Served only behind the
 * carton-trace permission — Owner, Plant Manager, Accounts. This is the ONE
 * carton surface that carries rates (FC-06, widened by the owner's word for
 * exactly this tier); the attribution block carries its bin-held-these-lots
 * sentence in `basis`, and no field in here names a bag→batch identity.
 *
 * @property array{carton: FinishedCarton, completion: array<string, mixed>, day_bin_attribution: array<string, mixed>, store_issue_attribution: array<string, mixed>, costing: array<string, mixed>} $resource
 */
class CartonInternalTraceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $carton = $this->resource['carton'];

        return [
            // The same public spine the scan already answers with, so the
            // internal screen needs no second request for the basics.
            'carton' => FinishedCartonResource::make($carton)->toArray($request),
            'completion' => $this->resource['completion'],
            'day_bin_attribution' => $this->resource['day_bin_attribution'],
            // The store-issue ledger's own block, under its own sentence —
            // never folded into the day bin's, whose wording is owner-fixed
            // (DEC-20260810-001) and speaks only of what the bin held.
            'store_issue_attribution' => $this->resource['store_issue_attribution'],
            'costing' => $this->resource['costing'],
        ];
    }
}
